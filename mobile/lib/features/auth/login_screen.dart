import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

import '../../core/auth/auth_models.dart';
import '../../core/auth/session_controller.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifier = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  String? _challenge;
  bool _obscure = true;

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_challenge != null) {
      await ref
          .read(sessionProvider.notifier)
          .verifyTwoFactor(_challenge!, _code.text.trim());
      return;
    }
    final response = await ref
        .read(sessionProvider.notifier)
        .login(_identifier.text.trim(), _password.text);
    if (response is TwoFactorRequired && mounted) {
      setState(() => _challenge = response.challengeToken);
    }
  }

  Future<void> _checkBiometric() async {
    final auth = LocalAuthentication();
    final available =
        await auth.canCheckBiometrics || await auth.isDeviceSupported();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          available
              ? 'Biometrik tersedia dan dapat diaktifkan setelah login.'
              : 'Biometrik tidak tersedia pada perangkat ini.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(sessionProvider);
    final busy = state.status == SessionStatus.signingIn;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Icon(
                      Icons.hub_rounded,
                      size: 64,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                    const SizedBox(height: 18),
                    Text(
                      _challenge == null
                          ? 'Masuk ke Quantara'
                          : 'Verifikasi 2 langkah',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineMedium
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      _challenge == null
                          ? 'Kelola pekerjaan dan layanan HR dari satu aplikasi.'
                          : 'Masukkan kode authenticator atau backup code.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 28),
                    if (_challenge == null) ...[
                      TextFormField(
                        controller: _identifier,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [
                          AutofillHints.username,
                          AutofillHints.email,
                        ],
                        decoration: const InputDecoration(
                          labelText: 'Email atau username',
                          prefixIcon: Icon(Icons.person_outline),
                        ),
                        validator: (value) =>
                            value == null || value.trim().isEmpty
                            ? 'Email atau username wajib diisi.'
                            : null,
                      ),
                      const SizedBox(height: 14),
                      TextFormField(
                        controller: _password,
                        obscureText: _obscure,
                        autofillHints: const [AutofillHints.password],
                        decoration: InputDecoration(
                          labelText: 'Password',
                          prefixIcon: const Icon(Icons.lock_outline),
                          suffixIcon: IconButton(
                            onPressed: () =>
                                setState(() => _obscure = !_obscure),
                            icon: Icon(
                              _obscure
                                  ? Icons.visibility_outlined
                                  : Icons.visibility_off_outlined,
                            ),
                          ),
                        ),
                        validator: (value) => value == null || value.isEmpty
                            ? 'Password wajib diisi.'
                            : null,
                      ),
                    ] else
                      TextFormField(
                        controller: _code,
                        autofocus: true,
                        keyboardType: TextInputType.number,
                        autofillHints: const [AutofillHints.oneTimeCode],
                        decoration: const InputDecoration(
                          labelText: 'Kode verifikasi',
                          prefixIcon: Icon(Icons.verified_user_outlined),
                        ),
                        validator: (value) =>
                            value == null || value.trim().isEmpty
                            ? 'Kode wajib diisi.'
                            : null,
                      ),
                    if (state.error != null) ...[
                      const SizedBox(height: 14),
                      Text(
                        state.error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ],
                    const SizedBox(height: 20),
                    FilledButton.icon(
                      onPressed: busy ? null : _submit,
                      icon: busy
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.login_rounded),
                      label: Text(_challenge == null ? 'Masuk' : 'Verifikasi'),
                    ),
                    if (_challenge != null)
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => setState(() {
                                _challenge = null;
                                _code.clear();
                              }),
                        child: const Text('Kembali ke login'),
                      )
                    else
                      TextButton.icon(
                        onPressed: _checkBiometric,
                        icon: const Icon(Icons.fingerprint),
                        label: const Text('Cek dukungan biometrik'),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
