class AppConfig {
  const AppConfig._();

  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8080/api/v1',
  );
  static const appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0',
  );
  static const appBuild = String.fromEnvironment(
    'APP_BUILD',
    defaultValue: '1',
  );
}
