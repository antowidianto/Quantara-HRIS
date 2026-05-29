/**
 * React Native CLI config — explicit overrides untuk autolinking.
 *
 * RN 0.76 + AGP 8 sudah pindah `package="com.hrismobile"` dari
 * AndroidManifest.xml ke `namespace "com.hrismobile"` di
 * `android/app/build.gradle`. Sebagian versi `@react-native-community/cli`
 * masih gagal auto-detect dari `namespace`, sehingga RNGP
 * `:app:generateAutolinkingPackageList` melempar error
 *
 *     "Could not find project.android.packageName".
 *
 * File ini meng-override CLI agar packageName eksplisit dibaca, sehingga
 * autolinking task dapat menyusun PackageList tanpa probing manifest.
 */
module.exports = {
  project: {
    android: {
      packageName: 'com.hrismobile',
    },
  },
};