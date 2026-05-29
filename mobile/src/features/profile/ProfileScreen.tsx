import React from 'react';
import {
  Alert,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { useAuthStore } from '@store/auth';
import { AuthApi } from '@features/auth/api';
import { env } from '@/config';
import { Outbox } from '@api/outbox';

/**
 * Profile + Settings — Tier 1.6.
 * MVP: tampil ringkas (full name, email, employee_id) + tombol logout +
 * info build version. Settings lebih dalam (2FA management, backup codes,
 * notification preferences) di-defer ke Sprint M2.
 */
export const ProfileScreen: React.FC = () => {
  const theme = useTheme();
  const user = useAuthStore((s) => s.user);
  const signOut = useAuthStore((s) => s.signOut);

  const handleLogout = () => {
    Alert.alert('Logout', 'Yakin keluar dari akun ini?', [
      { text: 'Batal', style: 'cancel' },
      {
        text: 'Logout',
        style: 'destructive',
        onPress: async () => {
          await AuthApi.logout();
          await signOut();
        },
      },
    ]);
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.h1, { color: c.text }]}>Profil</Text>

        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <View style={[styles.avatar, { backgroundColor: c.primary }]}>
            <Text style={[styles.avatarLetter, { color: c.primaryText }]}>
              {(user?.fullName ?? '?').slice(0, 1).toUpperCase()}
            </Text>
          </View>
          <Text style={[styles.name, { color: c.text }]}>{user?.fullName ?? '-'}</Text>
          <Text style={[styles.muted, { color: c.textMuted }]}>{user?.email ?? '-'}</Text>
          {user?.employeeId ? (
            <Text style={[styles.muted, { color: c.textMuted }]}>NIK: {user.employeeId}</Text>
          ) : null}
        </View>

        <Text style={[styles.section, { color: c.text }]}>Pengaturan</Text>
        <View style={[styles.list, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Item label="Ubah password" value="" onPress={() =>
            Alert.alert('Belum tersedia', 'Fitur ini di-rilis di Sprint M2.')} c={c} />
          <Item label="2FA / verifikasi 2 langkah" value="" onPress={() =>
            Alert.alert('Belum tersedia', 'Fitur ini di-rilis di Sprint M2.')} c={c} />
          <Item label="Notifikasi push" value="Aktif" onPress={() =>
            Alert.alert('Belum tersedia', 'Fitur ini di-rilis di Sprint M2.')} c={c} />
        </View>

        <Text style={[styles.section, { color: c.text }]}>Tentang</Text>
        <View style={[styles.list, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Item label="Versi aplikasi" value={`${env.appVersion} (build ${env.appBuild})`} c={c} />
          <Item label="Outbox pending" value={String(Outbox.count())} c={c} />
          <Item label="Privacy policy" value="" onPress={() =>
            Alert.alert('Privacy', 'Lihat https://hris.example.com/privacy')} c={c} />
        </View>

        <TouchableOpacity
          style={[styles.logoutBtn, { borderColor: c.danger }]}
          onPress={handleLogout}
        >
          <Text style={{ color: c.danger, fontWeight: '600', fontSize: 15 }}>Logout</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const Item: React.FC<{
  label: string;
  value: string;
  onPress?: () => void;
  c: { text: string; textMuted: string; border: string };
}> = ({ label, value, onPress, c }) => (
  <TouchableOpacity
    onPress={onPress}
    disabled={!onPress}
    style={[styles.itemRow, { borderColor: c.border }]}
  >
    <Text style={[styles.itemLabel, { color: c.text }]}>{label}</Text>
    <Text style={[styles.itemValue, { color: c.textMuted }]}>{value}</Text>
  </TouchableOpacity>
);

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  card: {
    marginTop: 16,
    padding: 24,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    alignItems: 'center',
  },
  avatar: {
    width: 72,
    height: 72,
    borderRadius: 36,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
  },
  avatarLetter: { fontSize: 28, fontWeight: '700' },
  name: { fontSize: 18, fontWeight: '600' },
  muted: { fontSize: 13, marginTop: 2 },
  section: { fontSize: 14, fontWeight: '700', marginTop: 24, marginBottom: 8 },
  list: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    overflow: 'hidden',
  },
  itemRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 14,
    paddingHorizontal: 16,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  itemLabel: { fontSize: 14, fontWeight: '500' },
  itemValue: { fontSize: 13 },
  logoutBtn: {
    marginTop: 28,
    paddingVertical: 14,
    borderRadius: 8,
    borderWidth: 1,
    alignItems: 'center',
  },
});