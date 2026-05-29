import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useMutation } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { SettingsApi } from './api';
import { ApiError } from '@api/client';

interface Props { onClose: () => void }

/**
 * Change password — Sprint M2.
 * Validasi minimal di client (12 char, mix). Backend `PasswordPolicy`
 * enforce R1-R6 (panjang, kompleksitas, common-pwd blacklist, history,
 * rotation). Error detail di-flatten dari ApiError.errors.
 */
export const ChangePasswordScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const [current, setCurrent] = useState('');
  const [next1, setNext1] = useState('');
  const [next2, setNext2] = useState('');

  const mut = useMutation({
    mutationFn: () => SettingsApi.changePassword(current, next1),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Password berhasil diubah. Silakan login ulang.');
      onClose();
    },
    onError: (e: unknown) => {
      if (e instanceof ApiError) {
        const detail = e.errors
          ? Object.values(e.errors).flat().join('\n')
          : e.message;
        Alert.alert('Gagal', detail);
      } else {
        Alert.alert('Gagal', 'Tidak bisa ubah password');
      }
    },
  });

  const handleSubmit = () => {
    if (!current || !next1) {
      Alert.alert('Validasi', 'Semua field wajib diisi');
      return;
    }
    if (next1.length < 12) {
      Alert.alert('Validasi', 'Password baru minimal 12 karakter');
      return;
    }
    if (next1 !== next2) {
      Alert.alert('Validasi', 'Konfirmasi password tidak cocok');
      return;
    }
    if (next1 === current) {
      Alert.alert('Validasi', 'Password baru harus berbeda dari yang lama');
      return;
    }
    mut.mutate();
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Text style={[styles.h1, { color: c.text }]}>Ubah Password</Text>
        <Text style={[styles.muted, { color: c.textMuted }]}>
          Password baru minimal 12 karakter, kombinasi huruf besar, kecil, angka & simbol.
        </Text>

        <Text style={[styles.label, { color: c.text }]}>Password saat ini</Text>
        <TextInput
          value={current}
          onChangeText={setCurrent}
          secureTextEntry
          autoCapitalize="none"
          placeholder="••••••••"
          placeholderTextColor={c.textMuted}
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <Text style={[styles.label, { color: c.text }]}>Password baru</Text>
        <TextInput
          value={next1}
          onChangeText={setNext1}
          secureTextEntry
          autoCapitalize="none"
          placeholder="Min. 12 karakter"
          placeholderTextColor={c.textMuted}
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <Text style={[styles.label, { color: c.text }]}>Konfirmasi password baru</Text>
        <TextInput
          value={next2}
          onChangeText={setNext2}
          secureTextEntry
          autoCapitalize="none"
          placeholder="Ulang password baru"
          placeholderTextColor={c.textMuted}
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <TouchableOpacity
          style={[styles.button, { backgroundColor: c.primary, opacity: mut.isPending ? 0.6 : 1 }]}
          onPress={handleSubmit}
          disabled={mut.isPending}
        >
          {mut.isPending ? (
            <ActivityIndicator color={c.primaryText} />
          ) : (
            <Text style={[styles.buttonText, { color: c.primaryText }]}>Simpan</Text>
          )}
        </TouchableOpacity>
        <TouchableOpacity onPress={onClose} style={styles.cancelBtn}>
          <Text style={{ color: c.textMuted, fontSize: 14 }}>Batal</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 20, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700', marginBottom: 8 },
  muted: { fontSize: 13, marginBottom: 16, lineHeight: 18 },
  label: { fontSize: 13, fontWeight: '600', marginTop: 12, marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 15,
  },
  button: {
    marginTop: 24,
    borderRadius: 8,
    paddingVertical: 14,
    alignItems: 'center',
  },
  buttonText: { fontSize: 16, fontWeight: '600' },
  cancelBtn: { alignItems: 'center', paddingVertical: 12, marginTop: 4 },
});