import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_models.dart';

final secureStorageProvider = Provider<SecureStorageService>(
  (_) => SecureStorageService(),
);

class SecureStorageService {
  SecureStorageService({this._storage = const FlutterSecureStorage()});

  static const _tokensKey = 'hris.auth.tokens';
  static const _userKey = 'hris.auth.user';
  final FlutterSecureStorage _storage;

  Future<AuthTokens?> loadTokens() async {
    final raw = await _storage.read(key: _tokensKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      return AuthTokens.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } on Object {
      await clear();
      return null;
    }
  }

  Future<CurrentUser?> loadUser() async {
    final raw = await _storage.read(key: _userKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      return CurrentUser.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } on Object {
      return null;
    }
  }

  Future<void> save(AuthTokens tokens, CurrentUser user) async {
    await Future.wait([
      _storage.write(key: _tokensKey, value: jsonEncode(tokens.toJson())),
      _storage.write(key: _userKey, value: jsonEncode(user.toJson())),
    ]);
  }

  Future<void> saveTokens(AuthTokens tokens) =>
      _storage.write(key: _tokensKey, value: jsonEncode(tokens.toJson()));

  Future<void> clear() => Future.wait([
    _storage.delete(key: _tokensKey),
    _storage.delete(key: _userKey),
  ]);
}
