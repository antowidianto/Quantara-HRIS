const { getDefaultConfig, mergeConfig } = require('@react-native/metro-config');

/**
 * Metro config — HRIS Mobile.
 * Default RN config + alias resolution selaras dengan tsconfig.json paths.
 */
const config = {
  resolver: {
    extraNodeModules: new Proxy(
      {},
      {
        get: (_target, name) => `${__dirname}/node_modules/${String(name)}`,
      },
    ),
  },
};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);