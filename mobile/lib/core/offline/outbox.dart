import 'dart:convert';
import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../network/api_client.dart';

final outboxProvider = Provider<OutboxService>(
  (ref) => OutboxService(ref.read(apiClientProvider)),
);

class OutboxEntry {
  const OutboxEntry({
    required this.id,
    required this.kind,
    required this.path,
    required this.payload,
    required this.idempotencyKey,
    required this.createdAt,
    required this.attempts,
    this.filePath,
    this.lastError,
  });

  final String id;
  final String kind;
  final String path;
  final Map<String, dynamic> payload;
  final String idempotencyKey;
  final DateTime createdAt;
  final int attempts;
  final String? filePath;
  final String? lastError;

  Map<String, dynamic> toJson() => {
    'id': id,
    'kind': kind,
    'path': path,
    'payload': payload,
    'idempotency_key': idempotencyKey,
    'created_at': createdAt.toIso8601String(),
    'attempts': attempts,
    'file_path': filePath,
    'last_error': lastError,
  };

  factory OutboxEntry.fromJson(Map<String, dynamic> json) => OutboxEntry(
    id: json['id'] as String,
    kind: json['kind'] as String,
    path: json['path'] as String,
    payload: Map<String, dynamic>.from(json['payload'] as Map),
    idempotencyKey: json['idempotency_key'] as String,
    createdAt: DateTime.parse(json['created_at'] as String),
    attempts: (json['attempts'] as num?)?.toInt() ?? 0,
    filePath: json['file_path'] as String?,
    lastError: json['last_error'] as String?,
  );

  OutboxEntry failed(String message) => OutboxEntry(
    id: id,
    kind: kind,
    path: path,
    payload: payload,
    idempotencyKey: idempotencyKey,
    createdAt: createdAt,
    attempts: attempts + 1,
    filePath: filePath,
    lastError: message,
  );
}

class OutboxService {
  OutboxService(this._api);
  static const _storageKey = 'hris.offline.outbox.v1';
  static const _uuid = Uuid();
  final ApiClient _api;
  Future<int>? _sweepFuture;

  Future<List<OutboxEntry>> list() async {
    final preferences = await SharedPreferences.getInstance();
    final raw = preferences.getString(_storageKey);
    if (raw == null || raw.isEmpty) return [];
    try {
      return (jsonDecode(raw) as List<dynamic>)
          .map(
            (item) =>
                OutboxEntry.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .toList()
        ..sort((a, b) => a.createdAt.compareTo(b.createdAt));
    } on Object {
      await preferences.remove(_storageKey);
      return [];
    }
  }

  Future<OutboxEntry> enqueue({
    required String kind,
    required String path,
    required Map<String, dynamic> payload,
    String? filePath,
  }) async {
    final entries = await list();
    final entry = OutboxEntry(
      id: _uuid.v4(),
      kind: kind,
      path: path,
      payload: payload,
      idempotencyKey: _uuid.v4(),
      createdAt: DateTime.now().toUtc(),
      attempts: 0,
      filePath: filePath,
    );
    await _save([...entries, entry]);
    return entry;
  }

  Future<int> sweep() {
    final running = _sweepFuture;
    if (running != null) return running;
    final future = _performSweep();
    _sweepFuture = future;
    return future.whenComplete(() => _sweepFuture = null);
  }

  Future<int> _performSweep() async {
    final entries = await list();
    final remaining = <OutboxEntry>[];
    var sent = 0;
    for (final entry in entries) {
      try {
        if (entry.filePath != null) {
          await _api.multipart<dynamic>(
            entry.path,
            entry.payload,
            fileField: 'selfie',
            filePath: entry.filePath!,
            idempotencyKey: entry.idempotencyKey,
          );
        } else {
          await _api.post<dynamic>(
            entry.path,
            data: entry.payload,
            idempotencyKey: entry.idempotencyKey,
          );
        }
        await _deleteAttachment(entry.filePath);
        sent++;
      } on ApiFailure catch (error) {
        final permanent =
            error.statusCode >= 400 &&
            error.statusCode < 500 &&
            error.statusCode != 401 &&
            error.statusCode != 409;
        if (permanent) {
          await _deleteAttachment(entry.filePath);
        } else {
          remaining.add(entry.failed(error.message));
        }
      } on Object catch (error) {
        remaining.add(entry.failed(error.toString()));
      }
    }
    await _save(remaining);
    return sent;
  }

  Future<void> clear() async {
    final entries = await list();
    for (final entry in entries) {
      await _deleteAttachment(entry.filePath);
    }
    await _save(const []);
  }

  Future<void> _deleteAttachment(String? path) async {
    if (path == null) return;
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } on Object {
      // Cleanup is best effort; the outbox state remains authoritative.
    }
  }

  Future<void> _save(List<OutboxEntry> entries) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(
      _storageKey,
      jsonEncode(entries.map((entry) => entry.toJson()).toList()),
    );
  }
}
