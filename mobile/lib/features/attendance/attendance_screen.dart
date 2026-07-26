import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../../core/network/api_client.dart';
import '../../core/offline/outbox.dart';
import '../common/feature_widgets.dart';

class AttendanceScreen extends ConsumerStatefulWidget {
  const AttendanceScreen({super.key});

  @override
  ConsumerState<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends ConsumerState<AttendanceScreen> {
  static const _uuid = Uuid();
  late Future<List<Map<String, dynamic>>> _records;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _records = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final raw = await ref
        .read(apiClientProvider)
        .get<dynamic>('/attendance/my');
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  Future<void> _refresh() async {
    setState(() => _records = _load());
    await _records;
  }

  Future<void> _clock(String action) async {
    setState(() => _submitting = true);
    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        throw Exception('Izin lokasi diperlukan untuk presensi.');
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
      final image = await ImagePicker().pickImage(
        source: ImageSource.camera,
        imageQuality: 82,
        maxWidth: 1600,
      );
      if (image == null) return;
      final directory = await getApplicationDocumentsDirectory();
      final savedPath =
          '${directory.path}${Platform.pathSeparator}attendance-${_uuid.v4()}.jpg';
      await File(image.path).copy(savedPath);
      final payload = <String, dynamic>{
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy_meters': position.accuracy,
        'client_at': DateTime.now().toUtc().toIso8601String(),
      };
      final endpoint = '/attendance/clock-$action';
      final connectivity = await Connectivity().checkConnectivity();
      final offline =
          connectivity.isEmpty ||
          connectivity.every((result) => result == ConnectivityResult.none);
      if (offline) {
        await ref
            .read(outboxProvider)
            .enqueue(
              kind: 'clock-$action',
              path: endpoint,
              payload: payload,
              filePath: savedPath,
            );
        _message('Presensi disimpan dan akan dikirim saat online.');
      } else {
        try {
          await ref
              .read(apiClientProvider)
              .multipart<dynamic>(
                endpoint,
                payload,
                fileField: 'selfie',
                filePath: savedPath,
              );
          await File(savedPath).delete();
          _message(
            action == 'in' ? 'Clock-in berhasil.' : 'Clock-out berhasil.',
          );
        } on ApiFailure catch (error) {
          if (error.isNetwork || error.statusCode >= 500) {
            await ref
                .read(outboxProvider)
                .enqueue(
                  kind: 'clock-$action',
                  path: endpoint,
                  payload: payload,
                  filePath: savedPath,
                );
            _message('Jaringan bermasalah. Presensi masuk antrean offline.');
          } else {
            await File(savedPath).delete().catchError((_) => File(savedPath));
            rethrow;
          }
        }
      }
      await _refresh();
    } on Object catch (error) {
      _message(error.toString(), error: true);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _message(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? Theme.of(context).colorScheme.error : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        FeatureHeader(
          title: 'Presensi',
          subtitle: 'GPS, selfie, dan antrean offline dengan idempotency key.',
          action: IconButton(
            onPressed: _refresh,
            icon: const Icon(Icons.refresh),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: _submitting ? null : () => _clock('in'),
                  icon: const Icon(Icons.login),
                  label: const Text('Clock-in'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _submitting ? null : () => _clock('out'),
                  icon: const Icon(Icons.logout),
                  label: const Text('Clock-out'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _records,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return EmptyState(
                  title: 'Riwayat gagal dimuat',
                  message: snapshot.error.toString(),
                  onRefresh: _refresh,
                );
              }
              final records = snapshot.data ?? const [];
              if (records.isEmpty) {
                return EmptyState(
                  title: 'Belum ada presensi',
                  onRefresh: _refresh,
                );
              }
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView.builder(
                  itemCount: records.length,
                  itemBuilder: (context, index) {
                    final record = records[index];
                    return FeatureCard(
                      title: record['work_date']?.toString() ?? 'Presensi',
                      fields: {
                        'Masuk': record['clock_in_at'],
                        'Pulang': record['clock_out_at'],
                        'Status': record['status'],
                        'Terlambat': record['late_minutes'],
                      },
                      leading: const CircleAvatar(child: Icon(Icons.schedule)),
                    );
                  },
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}
