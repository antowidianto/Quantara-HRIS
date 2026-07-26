import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../auth/auth_models.dart';
import '../auth/secure_storage.dart';
import '../config/app_config.dart';

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(ref));

class ApiFailure implements Exception {
  const ApiFailure({
    required this.message,
    required this.code,
    required this.statusCode,
    this.validationErrors,
    this.isNetwork = false,
  });

  final String message;
  final String code;
  final int statusCode;
  final Map<String, dynamic>? validationErrors;
  final bool isNetwork;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient(this._ref)
    : _dio = Dio(
        BaseOptions(
          baseUrl: AppConfig.apiBaseUrl,
          connectTimeout: const Duration(seconds: 20),
          receiveTimeout: const Duration(seconds: 20),
          headers: const {
            Headers.acceptHeader: Headers.jsonContentType,
            'X-App-Version': AppConfig.appVersion,
            'X-App-Build': AppConfig.appBuild,
          },
        ),
      ) {
    _dio.interceptors.add(
      InterceptorsWrapper(onRequest: _onRequest, onError: _onError),
    );
  }

  static const _publicPaths = {
    '/auth/login',
    '/auth/refresh',
    '/auth/verify-2fa',
    '/auth/forgot-password',
    '/auth/reset-password',
    '/health',
    '/openapi.json',
  };
  static const _mutationMethods = {'POST', 'PUT', 'PATCH', 'DELETE'};
  static const _uuid = Uuid();

  final Ref _ref;
  final Dio _dio;
  Future<AuthTokens?>? _refreshFuture;
  int? activeCompanyId;

  Future<void> _onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final path = options.path;
    final skipAuth = options.extra['skipAuth'] == true;
    if (!skipAuth && !_publicPaths.contains(path)) {
      final tokens = await _ref.read(secureStorageProvider).loadTokens();
      if (tokens != null) {
        options.headers[HttpHeaders.authorizationHeader] =
            'Bearer ${tokens.accessToken}';
      }
    }
    if (activeCompanyId case final companyId? when companyId > 0) {
      options.headers['X-Company-Id'] = companyId.toString();
    }
    if (_mutationMethods.contains(options.method.toUpperCase())) {
      options.headers.putIfAbsent(
        'Idempotency-Key',
        () => options.extra['idempotencyKey'] ?? _uuid.v4(),
      );
    }
    handler.next(options);
  }

  Future<void> _onError(
    DioException error,
    ErrorInterceptorHandler handler,
  ) async {
    final request = error.requestOptions;
    if (error.response?.statusCode == 401 &&
        request.extra['retried'] != true &&
        !_publicPaths.contains(request.path)) {
      request.extra['retried'] = true;
      final fresh = await _refresh();
      if (fresh != null) {
        request.headers[HttpHeaders.authorizationHeader] =
            'Bearer ${fresh.accessToken}';
        try {
          handler.resolve(await _dio.fetch<dynamic>(request));
          return;
        } on DioException catch (retryError) {
          handler.reject(retryError);
          return;
        }
      }
      await _ref.read(secureStorageProvider).clear();
    }
    handler.reject(error);
  }

  Future<AuthTokens?> _refresh() {
    final current = _refreshFuture;
    if (current != null) return current;
    final future = _performRefresh();
    _refreshFuture = future;
    return future.whenComplete(() => _refreshFuture = null);
  }

  Future<AuthTokens?> _performRefresh() async {
    try {
      final storage = _ref.read(secureStorageProvider);
      final current = await storage.loadTokens();
      if (current == null) return null;
      final plainDio = Dio(
        BaseOptions(
          baseUrl: AppConfig.apiBaseUrl,
          connectTimeout: const Duration(seconds: 15),
        ),
      );
      final response = await plainDio.post<Map<String, dynamic>>(
        '/auth/refresh',
        data: {'refresh_token': current.refreshToken},
        options: Options(headers: {'Idempotency-Key': _uuid.v4()}),
      );
      final data = _unwrap(response.data);
      if (data is! Map<String, dynamic>) return null;
      final accessToken = data['access_token'] as String?;
      final refreshToken = data['refresh_token'] as String?;
      if (accessToken == null || refreshToken == null) return null;
      final tokens = AuthTokens(
        accessToken: accessToken,
        refreshToken: refreshToken,
        expiresAt: DateTime.now().add(
          Duration(seconds: (data['expires_in'] as num?)?.toInt() ?? 900),
        ),
        userId: current.userId,
      );
      await storage.saveTokens(tokens);
      return tokens;
    } on Object {
      return null;
    }
  }

  Future<T> get<T>(String path, {Map<String, dynamic>? query}) async {
    try {
      final response = await _dio.get<dynamic>(path, queryParameters: query);
      return _unwrap(response.data) as T;
    } on DioException catch (error) {
      throw _toFailure(error);
    }
  }

  Future<T> post<T>(
    String path, {
    Object? data,
    String? idempotencyKey,
    bool skipAuth = false,
  }) async {
    try {
      final response = await _dio.post<dynamic>(
        path,
        data: data,
        options: Options(
          extra: {
            // ignore: use_null_aware_elements
            if (idempotencyKey != null) 'idempotencyKey': idempotencyKey,
            if (skipAuth) 'skipAuth': true,
          },
        ),
      );
      return _unwrap(response.data) as T;
    } on DioException catch (error) {
      throw _toFailure(error);
    }
  }

  Future<T> put<T>(String path, {Object? data}) async {
    try {
      final response = await _dio.put<dynamic>(path, data: data);
      return _unwrap(response.data) as T;
    } on DioException catch (error) {
      throw _toFailure(error);
    }
  }

  Future<T> delete<T>(String path) async {
    try {
      final response = await _dio.delete<dynamic>(path);
      return _unwrap(response.data) as T;
    } on DioException catch (error) {
      throw _toFailure(error);
    }
  }

  Future<T> multipart<T>(
    String path,
    Map<String, dynamic> fields, {
    required String fileField,
    required String filePath,
    String? idempotencyKey,
  }) async {
    final data = FormData.fromMap({
      ...fields,
      fileField: await MultipartFile.fromFile(
        filePath,
        filename: filePath.split(Platform.pathSeparator).last,
      ),
    });
    return post<T>(path, data: data, idempotencyKey: idempotencyKey);
  }

  static dynamic _unwrap(dynamic body) {
    if (body is Map<String, dynamic> && body.containsKey('data')) {
      return body['data'];
    }
    return body;
  }

  static ApiFailure _toFailure(DioException error) {
    final response = error.response;
    if (response == null) {
      return ApiFailure(
        message: error.message ?? 'Tidak dapat terhubung ke server.',
        code: 'NETWORK_ERROR',
        statusCode: 0,
        isNetwork: true,
      );
    }
    final body = response.data;
    final map = body is Map<String, dynamic> ? body : null;
    return ApiFailure(
      message: map?['message'] as String? ?? 'HTTP ${response.statusCode}',
      code: map?['code'] as String? ?? 'HTTP_${response.statusCode ?? 0}',
      statusCode: response.statusCode ?? 0,
      validationErrors: map?['errors'] as Map<String, dynamic>?,
    );
  }
}
