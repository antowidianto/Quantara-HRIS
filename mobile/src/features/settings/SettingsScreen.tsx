import React, { useState } from 'react';
import { ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { ChangePasswordScreen } from './ChangePasswordScreen';
import { TwoFactorManageScreen } from './TwoFactorManageScreen';
import { PreferencesScreen } from './PreferencesScreen';

interface Props { onClose: () => void }

type Sub = 'menu' | 'password' | '2fa' | 'prefs';

/**
 * Settings — Sprint M2 + M5.
 * Akses dari Profil → "Pengaturan".
 *
 * M5: tambah row "Preferensi" untuk language switcher + idle threshold +
 * biometric toggle.
 */
export const SettingsScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const [sub, setSub] = useState<Sub>('menu');

  if (sub === 'password') return <ChangePasswordScreen onClose={() => setSub('menu')} />;
  if (sub === '2fa')      return <TwoFactorManageScreen onClose={() => setSub('menu')} />;
  if (sub === 'prefs')    return <PreferencesScreen onClose={() => setSub('menu')} />;

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.h1, { color: c.text }]}>Pengaturan</Text>

        <View style={[styles.list, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Row
            label="Ubah password"
            sub="Update password login"
            onPress={() => setSub('password')}
            colors={c}
          />
          <Row
            label="Verifikasi 2 langkah"
            sub="TOTP authenticator + biometrik"
            onPress={() => setSub('2fa')}
            colors={c}
          />
          <Row
            label="Preferensi"
            sub="Bahasa, auto-lock, biometrik"
            onPress={() => setSub('prefs')}
            colors={c}
          />
        </View>

        <TouchableOpacity onPress={onClose} style={styles.closeBtn}>
          <Text style={{ color: c.textMuted, fontSize: 14 }}>Tutup</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const Row: React.FC<{
  label: string;
  sub: string;
  onPress: () => void;
  colors: { text: string; textMuted: string; border: string };
}> = ({ label, sub, onPress, colors: c }) => (
  <TouchableOpacity onPress={onPress} style={[styles.row, { borderColor: c.border }]}>
    <View style={{ flex: 1 }}>
      <Text style={[styles.rowLabel, { color: c.text }]}>{label}</Text>
      <Text style={[styles.rowSub, { color: c.textMuted }]}>{sub}</Text>
    </View>
    <Text style={{ color: c.textMuted, fontSize: 18 }}>›</Text>
  </TouchableOpacity>
);

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700', marginBottom: 16 },
  list: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    overflow: 'hidden',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  rowLabel: { fontSize: 15, fontWeight: '500' },
  rowSub: { fontSize: 12, marginTop: 2 },
  closeBtn: { alignItems: 'center', paddingVertical: 12, marginTop: 12 },
});