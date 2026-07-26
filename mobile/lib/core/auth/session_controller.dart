import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../notifications/push_service.dart';
import '../offline/outbox.dart';
import 'auth_models.dart';
import 'auth_repository.dart';
import 'secure_storage.dart';

enum SessionStatus { booting, signedOut, signingIn, signedIn }

class SessionState {
  const SessionState({required this.status, this.user, this.error});

  const SessionState.booting() : this(status: SessionStatus.booting);
  const SessionState.signedOut([String? error])
    : this(status: SessionStatus.signedOut, error: error);

  final SessionStatus status;
  final CurrentUser? user;
  final String? error;
  bool get isAuthenticated => status == SessionStatus.signedIn && user != null;

  SessionState copyWith({
    SessionStatus? status,
    CurrentUser? user,
    String? error,
    bool clearError = false,
  }) => SessionState(
    status: status ?? this.status,
    user: user ?? this.user,
    error: clearError ? null : error ?? this.error,
  );
}

final sessionProvider = NotifierProvider<SessionController, SessionState>(
  SessionController.new,
);

class SessionController extends Notifier<SessionState> {
  @override
  SessionState build() => const SessionState.booting();

  Future<void> bootstrap() async {
    final storage = ref.read(secureStorageProvider);
    final tokens = await storage.loadTokens();
    if (tokens == null) {
      state = const SessionState.signedOut();
      return;
    }
    final cachedUser = await storage.loadUser();
    if (cachedUser != null) _activateCompany(cachedUser);
    try {
      final user = await ref.read(authRepositoryProvider).me();
      await storage.save(tokens, user);
      _activateCompany(user);
      state = SessionState(status: SessionStatus.signedIn, user: user);
    } on Object {
      await storage.clear();
      state = const SessionState.signedOut('Sesi berakhir. Silakan login.');
    }
  }

  Future<LoginResponse?> login(String identifier, String password) async {
    state = state.copyWith(status: SessionStatus.signingIn, clearError: true);
    try {
      final result = await ref
          .read(authRepositoryProvider)
          .login(identifier, password);
      if (result is LoginSuccess) await _accept(result);
      if (result is TwoFactorRequired) {
        state = const SessionState.signedOut();
      }
      return result;
    } on Object catch (error) {
      state = SessionState.signedOut(_message(error));
      return null;
    }
  }

  Future<bool> verifyTwoFactor(String challenge, String code) async {
    state = state.copyWith(status: SessionStatus.signingIn, clearError: true);
    try {
      final result = await ref
          .read(authRepositoryProvider)
          .verifyTwoFactor(challenge, code);
      await _accept(result);
      return true;
    } on Object catch (error) {
      state = SessionState.signedOut(_message(error));
      return false;
    }
  }

  Future<void> _accept(LoginSuccess result) async {
    await ref.read(secureStorageProvider).save(result.tokens, result.user);
    _activateCompany(result.user);
    state = SessionState(status: SessionStatus.signedIn, user: result.user);
  }

  void _activateCompany(CurrentUser user) {
    ref.read(apiClientProvider).activeCompanyId = user.isSuperAdmin
        ? user.companyId
        : null;
  }

  Future<void> signOut() async {
    await ref.read(pushServiceProvider).unregister();
    await ref.read(authRepositoryProvider).logout();
    await ref.read(secureStorageProvider).clear();
    await ref.read(outboxProvider).clear();
    ref.read(apiClientProvider).activeCompanyId = null;
    state = const SessionState.signedOut();
  }

  static String _message(Object error) =>
      error is ApiFailure ? error.message : 'Terjadi kesalahan. Coba lagi.';
}
