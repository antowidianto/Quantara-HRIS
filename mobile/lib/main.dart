import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';

@pragma('vm:entry-point')
Future<void> handleBackgroundPush(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } on Object {
    // Firebase native config is environment-specific. The app remains usable
    // when it has not been supplied yet.
  }
}

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  FirebaseMessaging.onBackgroundMessage(handleBackgroundPush);
  runApp(const ProviderScope(child: HrisApp()));
}
