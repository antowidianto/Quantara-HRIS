import 'package:flutter_test/flutter_test.dart';
import 'package:hris_mobile/core/auth/auth_models.dart';

void main() {
  test('auth tokens survive secure-storage JSON round trip', () {
    final tokens = AuthTokens(
      accessToken: 'access',
      refreshToken: 'refresh',
      expiresAt: DateTime.utc(2026, 7, 26),
      userId: 42,
    );

    final restored = AuthTokens.fromJson(tokens.toJson());

    expect(restored.accessToken, 'access');
    expect(restored.refreshToken, 'refresh');
    expect(restored.expiresAt, DateTime.utc(2026, 7, 26));
    expect(restored.userId, 42);
  });

  test('super admin is inferred from role id one', () {
    final user = CurrentUser.fromJson({
      'id': 1,
      'email': 'admin@example.test',
      'full_name': 'Admin',
      'role_id': 1,
      'company_id': 9,
    });

    expect(user.isSuperAdmin, isTrue);
    expect(user.companyId, 9);
  });
}
