import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { ForgotApi } from './forgot';

interface Props {
  onClose: () => void;
}

/**
 * Forgot password — Sprint M1.
 * Identifier accept email atau NIK (sama seperti login). Backend
 * tidak enumerate akun: response selalu OK supaya attacker tidak bisa
 * tahu email mana yang valid.
 */
export const ForgotPasswordScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const [identifier, setIdentifier] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async () => {
    if (!identifier.trim()) {
      Alert.alert('Validasi', 'Email/NIK wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await ForgotApi.request(identifier);
      Alert.alert(
        'Permintaan dikirim',
        'Kalau email kamu terdaftar, kami akan kirim link reset password. Cek inbox/spam.',
        [{ text: 'OK', onPress: onClose }],
      );
    } catch {
      // Tetap tampilkan pesan generic — anti enumeration.
      Alert.alert('Permintaan dikirim',
        'Kalau email kamu terdaftar, kami akan kirim link reset password.');
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <View style={styles.content}>
        <Text style={[styles.title, { color: c.text }]}>Lupa password?</Text>
        <Text style={[styles.subtitle, { color: c.textMuted }]}>
          Masukkan email atau NIK akun. Kami akan kirim link untuk reset password.
        </Text>

        <Text style={[styles.label, { color: c.text }]}>Email atau NIK</Text>
        <TextInput
          value={identifier}
          onChangeText={setIdentifier}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          placeholder="user@perusahaan.id atau 1234567"
          placeholderTextColor={c.textMuted}
          editable={!submitting}
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <TouchableOpacity
          style={[styles.button, { backgroundColor: c.primary, opacity: submitting ? 0.6 : 1 }]}
          onPress={handleSubmit}
          disabled={submitting}
        >
          {submitting ? (
            <ActivityIndicator color={c.primaryText} />
          ) : (
            <Text style={[styles.buttonText, { color: c.primaryText }]}>Kirim link reset</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity onPress={onClose} style={styles.linkBtn}>
          <Text style={{ color: c.textMuted, fontSize: 13 }}>Kembali ke login</Text>
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { flex: 1, padding: 24, justifyContent: 'center' },
  title: { fontSize: 24, fontWeight: '700', marginBottom: 8 },
  subtitle: { fontSize: 14, marginBottom: 24, lineHeight: 20 },
  label: { fontSize: 13, fontWeight: '600', marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 15,
  },
  button: { marginTop: 20, borderRadius: 8, paddingVertical: 14, alignItems: 'center' },
  buttonText: { fontSize: 16, fontWeight: '600' },
  linkBtn: { alignItems: 'center', paddingVertical: 12, marginTop: 4 },
});