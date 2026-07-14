/**
 * Jest setup — HRIS Mobile.
 *
 * Mock native modules yang tidak tersedia di Node test runtime:
 *   - react-native-keychain (pakai memory)
 *   - react-native-mmkv (pakai memory map)
 *   - react-native-config (pakai object)
 *   - react-native-device-info (pakai stub values)
 *   - @react-native-community/netinfo (pakai always-online)
 *   - react-native-geolocation-service (pakai fixture coords)
 */

jest.mock('react-native-keychain', () => {
  let store = null;
  return {
    ACCESSIBLE: { WHEN_UNLOCKED: 'WhenUnlocked' },
    setGenericPassword: jest.fn(async (_user, password) => {
      store = { password };
      return true;
    }),
    getGenericPassword: jest.fn(async () => (store ? { ...store } : false)),
    resetGenericPassword: jest.fn(async () => {
      store = null;
      return true;
    }),
  };
});

jest.mock('react-native-mmkv', () => {
  class MMKV {
    constructor() {
      this._map = new Map();
    }
    set(k, v) { this._map.set(k, v); }
    getString(k) { return this._map.get(k); }
    getAllKeys() { return Array.from(this._map.keys()); }
    delete(k) { return this._map.delete(k); }
    remove(k) { return this._map.delete(k); }
  }
  return { MMKV, createMMKV: () => new MMKV() };
});

jest.mock('react-native-config', () => ({
  default: {
    API_BASE_URL: 'http://localhost/api/v1',
    APP_VERSION: '0.1.0',
    APP_BUILD: '1',
  },
  __esModule: true,
}));

jest.mock('react-native-device-info', () => ({
  default: {
    getUniqueId: jest.fn(async () => 'test-device-id'),
    getModel: () => 'TestPhone',
    getSystemVersion: () => '15',
    getVersion: () => '0.1.0',
  },
  __esModule: true,
}));

jest.mock('@react-native-community/netinfo', () => ({
  default: {
    fetch: jest.fn(async () => ({ isConnected: true, isInternetReachable: true })),
    addEventListener: jest.fn(() => () => undefined),
  },
  __esModule: true,
}));

jest.mock('react-native-geolocation-service', () => ({
  default: {
    getCurrentPosition: (success) => success({
      coords: { latitude: -6.2, longitude: 106.8, accuracy: 5 },
      timestamp: Date.now(),
    }),
  },
  __esModule: true,
}));

jest.mock('react-native-biometrics', () => {
  class ReactNativeBiometrics {
    isSensorAvailable = jest.fn(async () => ({ available: false, biometryType: undefined }));
    simplePrompt = jest.fn(async () => ({ success: false }));
    createKeys = jest.fn(async () => ({ publicKey: 'stub' }));
    biometricKeysExist = jest.fn(async () => ({ keysExist: false }));
    deleteKeys = jest.fn(async () => ({ keysDeleted: true }));
    createSignature = jest.fn(async () => ({ success: false, signature: '' }));
  }
  return {
    __esModule: true,
    default: ReactNativeBiometrics,
    BiometryTypes: { TouchID: 'TouchID', FaceID: 'FaceID', Biometrics: 'Biometrics' },
  };
});

jest.mock('react-native-permissions', () => ({
  __esModule: true,
  PERMISSIONS: { IOS: {}, ANDROID: {} },
  RESULTS: { GRANTED: 'granted', DENIED: 'denied', BLOCKED: 'blocked', UNAVAILABLE: 'unavailable' },
  check: jest.fn(async () => 'granted'),
  request: jest.fn(async () => 'granted'),
  requestMultiple: jest.fn(async () => ({})),
}));

// uuid v9+ ships an ESM-only module under dist/esm-browser. Jest tidak
// transform node_modules secara default. Mock di sini agar test pure logic
// (Outbox, dll) tidak butuh real uuid.
jest.mock('uuid', () => ({
  __esModule: true,
  v4: () => 'test-uuid-' + Math.random().toString(36).slice(2, 10),
}));

jest.mock('react-native', () => ({
  Platform: { OS: 'ios', select: (obj) => obj.ios },
  PermissionsAndroid: {
    PERMISSIONS: { ACCESS_FINE_LOCATION: 'fine_location' },
    RESULTS: { GRANTED: 'granted' },
    request: jest.fn(async () => 'granted'),
  },
  AppState: { addEventListener: jest.fn(() => ({ remove: () => undefined })) },
  NativeModules: {
    ReactNativeBiometrics: {},
    RNCNetInfo: {},
  },
  NativeEventEmitter: class {
    addListener() { return { remove: () => undefined }; }
    removeAllListeners() {}
  },
}));
