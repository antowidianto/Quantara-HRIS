import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Platform, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { checkVersionGate, type VersionGateResult } from '@/lib/versionCheck';
import { t } from '@/i18n';

/**
 * ForceUpdateGate — Sprint M5.
 *
 * Saat app start, panggil `checkVersionGate()`. Hasil:
 *   - 'force' → render fullscreen blocker dengan tombol ke store URL.
 *               UI utama tidak akan ter-mount sama sekali.
 *   - 'recommended' → render banner di atas children, non-blocking.
 *   - 'ok' atau status lain → render children seperti biasa.
 *
 * Endpoint pakai pattern fail-open: kalau fetch gagal (offline / 404 /
 * timeout), `checkVersionGate` return 'ok' supaya app tetap pakai
 * — kita tidak mau lock user gara-gara backend hiccup.
 */

interface Props {
  children: React.ReactNode;
}

export const ForceUpdateGate: React.FC<Props> = ({ children }) => {
  const [result, setResult] = useState<VersionGateResult | null>(null);
  const [dismissed, setDismissed] = useState<boolean>(false);
  const theme = useTheme();
  const c = theme.colors;

  useEffect(() => {
    let mounted = true;
    checkVersionGate()
      .then((r) => {
        if (mounted) setResult(r);
      })
      .catch(() => {
        if (mounted) setResult({ status: 'ok' });
      });
    return () => {
      mounted = false;
    };
  }, []);

  // Belum ada hasil → tampilkan children dulu (asumsi optimistik). Gate
  // hanya muncul kalau backend benar-benar bilang force-update. Latency
  // 50–200ms tidak worth nge-block UI.
  if (!result || result.status === 'ok') return <>{children}</>;

  if (result.status === 'force') {
    const url = result.updateUrl;
    return (
      <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
        <View style={styles.body}>
          <Text style={styles.icon}>⚠️</Text>
          <Text style={[styles.h1, { color: c.text }]}>{t('force.title')}</Text>
          <Text style={[styles.body2, { color: c.textMuted }]}>
            {result.message ?? t('force.body')}
          </Text>
          {result.minSupported && result.latest && (
            <Text style={[styles.versionInfo, { color: c.textMuted }]}>
              Min: {result.minSupported} • Latest: {result.latest}
            </Text>
          )}
          {url && (
            <TouchableOpacity
              onPress={() => Linking.openURL(url).catch(() => undefined)}
              style={[styles.btn, { backgroundColor: c.primary }]}
            >
              <Text style={[styles.btnText, { color: c.primaryText }]}>
                {t('force.update')}
              </Text>
            </TouchableOpacity>
          )}
          {!url && (
            <Text style={[styles.body2, { color: c.textMuted, marginTop: 24 }]}>
              Hubungi IT helpdesk untuk link update. Platform: {Platform.OS}
            </Text>
          )}
        </View>
      </SafeAreaView>
    );
  }

  // Soft recommend — banner non-blocking di atas children
  if (result.status === 'recommended' && !dismissed) {
    return (
      <View style={{ flex: 1 }}>
        <View style={[styles.banner, { backgroundColor: c.warning }]}>
          <Text style={styles.bannerText} numberOfLines={2}>
            Versi baru tersedia ({result.latest}). Tap untuk update.
          </Text>
          <View style={styles.bannerActions}>
            {result.updateUrl && (
              <TouchableOpacity
                onPress={() => Linking.openURL(result.updateUrl!).catch(() => undefined)}
              >
                <Text style={styles.bannerLink}>Update</Text>
              </TouchableOpacity>
            )}
            <TouchableOpacity onPress={() => setDismissed(true)}>
              <Text style={styles.bannerLink}>Tutup</Text>
            </TouchableOpacity>
          </View>
        </View>
        <View style={{ flex: 1 }}>{children}</View>
      </View>
    );
  }

  return <>{children}</>;
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  body: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 32,
  },
  icon: { fontSize: 48, marginBottom: 16 },
  h1: { fontSize: 22, fontWeight: '700', textAlign: 'center' },
  body2: { fontSize: 14, marginTop: 8, textAlign: 'center', lineHeight: 20 },
  versionInfo: { fontSize: 12, marginTop: 8 },
  btn: {
    marginTop: 32,
    paddingVertical: 14,
    paddingHorizontal: 40,
    borderRadius: 8,
    minWidth: 200,
    alignItems: 'center',
  },
  btnText: { fontSize: 15, fontWeight: '600' },
  banner: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  bannerText: { flex: 1, color: '#fff', fontSize: 13, fontWeight: '600' },
  bannerActions: { flexDirection: 'row', gap: 12 },
  bannerLink: { color: '#fff', fontSize: 13, fontWeight: '700', paddingHorizontal: 4 },
});

/** Loader fallback selama gate masih fetch — tidak dipakai default. */
export const VersionGateLoading: React.FC = () => {
  const theme = useTheme();
  return (
    <View style={[styles.root, { backgroundColor: theme.colors.bg, alignItems: 'center', justifyContent: 'center' }]}>
      <ActivityIndicator color={theme.colors.primary} />
    </View>
  );
};