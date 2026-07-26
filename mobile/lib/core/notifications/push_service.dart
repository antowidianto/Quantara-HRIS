import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import '../network/api_client.dart';

final pushServiceProvider = Provider<PushService>((ref) {
  final service = PushService(ref.read(apiClientProvider));
  ref.onDispose(service.dispose);
  return service;
});

class PushService {
  PushService(this._api);

  static const _deviceIdKey = 'push.device_id';
  static const _uuid = Uuid();

  final ApiClient _api;
  StreamSubscription<String>? _tokenSubscription;
  bool _initialized = false;

  Future<void> initializeAndSync() async {
    if (_initialized) return;
    _initialized = true;
    try {
      await Firebase.initializeApp();
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(alert: true, badge: true, sound: true);
      final token = await messaging.getToken();
      if (token != null) await _register(token);
      _tokenSubscription = messaging.onTokenRefresh.listen((token) {
        unawaited(_register(token));
      });
    } on Object {
      // Firebase configuration is injected per environment. Push must not
      // block login or the rest of the HRIS application when it is absent.
    }
  }

  Future<void> unregister() async {
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) {
        await _api.post<dynamic>('/devices/unregister', data: {'token': token});
      }
    } on Object {
      // Best effort during sign-out.
    }
  }

  Future<void> _register(String token) async {
    final preferences = await SharedPreferences.getInstance();
    var deviceId = preferences.getString(_deviceIdKey);
    if (deviceId == null) {
      deviceId = _uuid.v4();
      await preferences.setString(_deviceIdKey, deviceId);
    }
    await _api.post<dynamic>(
      '/devices/register',
      data: {
        'platform': Platform.isIOS ? 'IOS' : 'ANDROID',
        'token': token,
        'device_id': deviceId,
        'device_model': Platform.operatingSystem,
        'os_version': Platform.operatingSystemVersion,
        'app_version': AppConfig.appVersion,
        'locale': Platform.localeName,
        'timezone': DateTime.now().timeZoneName,
      },
    );
  }

  void dispose() {
    _tokenSubscription?.cancel();
  }
}
