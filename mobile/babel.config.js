module.exports = {
  presets: ['module:@react-native/babel-preset'],
  plugins: [
    [
      'module-resolver',
      {
        root: ['./src'],
        alias: {
          '@': './src',
          '@api': './src/api',
          '@features': './src/features',
          '@components': './src/components',
          '@theme': './src/theme',
          '@store': './src/store',
        },
      },
    ],
  ],
};