import React, { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { Biometric } from '@features/auth/biometric';
import { IdleLock } from '@/lib/idleLock';
import { t } from '@/i18n';

/**
 * IdleLockGate — Sprint M5.
 *
 * Render fullscreen overlay saat `IdleLock.isLocked()`. Pengguna wajib
 * verifikasi biometric (atau sign-out) untuk lanjut. Mount-nya
 * mengelilingi MainTabs supaya UI utama tidak bisa di-akses sebelum
 * unlock.
 *
 * Kenapa dipisah dari App.tsx Boot:
 *  - Isolasi efek polling (200ms tick) supaya tidak nyusup ke Boot
 *    yang lebih kompleks.
 *  - Reusable kalau nanti ada flow lain yang mau force re-auth
 *    (mis. sebelum sign offer letter, sebelum payslip download).
 *
 * Polling pakai interval 1 detik, bukan event listener. Alasan:
 *  - `mmkv.set` saat `IdleLock.lock()` tidak emit event ke React.
 *  - Bisa di-upgrade ke MMKV listener nanti tanpa breaking signature.
 */

interface Props {
  children: React.ReactNode;
  onSignOut: () => void;
}

export const IdleLockGate: React.FC<Props> = ({ children, onSignOut }) => {
  const [locked, setLocked] = useState<boolean>(IdleLock.isLocked());
  const [busy, setBusy] = useState<boolean>(false);
  const theme = useTheme();
  const c = theme.colors;

  useEffect(() => {
    const id = setInterval(() => {
      const cur = IdleLock.isLocked();
      // Hanya setState saat berubah supaya tidak trigger re-render terus
      setLocked((prev) => (prev !== cur ? cur : prev));
    }, 1000);
    return () => clearInterval(id);
  }, []);

  const handleUnlock = async () => {
    if (busy) return;
    setBusy(true);
    try {
      const ok = await Biometric.authenticate(t('idle.body'));
      if (ok) {
        IdleLock.unlock();
        setLocked(false);
      }
    } finally {
      setBusy(false);
    }
  };

  if (!locked) return <>{children}</>;

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <View style={styles.body}>
        <Text style={styles.lockIcon}>🔒</Text>
        <Text style={[styles.h1, { color: c.text }]}>{t('idle.title')}</Text>
        <Text style={[styles.body2, { color: c.textMuted }]}>{t('idle.body')}</Text>

        <TouchableOpacity
          onPress={handleUnlock}
          disabled={busy}
          style={[styles.btn, { backgroundColor: c.primary, opacity: busy ? 0.6 : 1 }]}
        >
          {busy ? (
            <ActivityIndicator color={c.primaryText} />
          ) : (
            <Text style={[styles.btnText, { color: c.primaryText }]}>{t('idle.unlock')}</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity
          onPress={() => {
            IdleLock.unlock();
            onSignOut();
          }}
          style={styles.signoutBtn}
        >
          <Text style={[styles.signoutText, { color: c.textMuted }]}>{t('idle.signout')}</Text>
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  body: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 32,
  },
  lockIcon: { fontSize: 56, marginBottom: 16 },
  h1: { fontSize: 22, fontWeight: '700', textAlign: 'center' },
  body2: { fontSize: 14, marginTop: 8, textAlign: 'center', lineHeight: 20 },
  btn: {
    marginTop: 32,
    paddingVertical: 14,
    paddingHorizontal: 40,
    borderRadius: 8,
    minWidth: 200,
    alignItems: 'center',
  },
  btnText: { fontSize: 15, fontWeight: '600' },
  signoutBtn: { marginTop: 16, paddingVertical: 8 },
  signoutText: { fontSize: 13 },
});