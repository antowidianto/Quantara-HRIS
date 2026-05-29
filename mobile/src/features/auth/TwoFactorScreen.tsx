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
import { useAuthStore } from '@store/auth';
import { AuthApi, type TwoFactorChallenge } from './api';
import { ApiError } from '@api/client';

interface Props {
  challenge: TwoFactorChallenge;
  onCancel: () => void;
}

/**
 * 2FA verify screen — TOTP atau backup code (8-digit hex/UUID-ish).
 * Backup code field di-toggle via link "Pakai backup code" supaya kasus
 * "HP authenticator hilang" tetap bisa login.
 */
export const TwoFactorScreen: React.FC<Props> = ({ challenge, onCancel }) => {
  const theme = useTheme();
  const setSession = useAuthStore((s) => s.setSession);
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [useBackup, setUseBackup] = useState(false);

  const handleSubmit = async () => {
    if (!code) {
      Alert.alert('Validasi', 'Kode 2FA wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      const result = await AuthApi.verifyTwoFactor(challenge.challengeToken, code.trim());
      await setSession(result.user, result.tokens);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Kode tidak valid';
      Alert.alert('Verifikasi gagal', msg);
    } finally {
      setSubmitting(false);
    }
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <View style={styles.content}>
        <Text style={[styles.title, { color: c.text }]}>Verifikasi 2 langkah</Text>
        <Text style={[styles.subtitle, { color: c.textMuted }]}>
          Masukkan {useBackup ? 'kode backup' : 'kode 6 digit dari aplikasi authenticator'}.
        </Text>

        <TextInput
          value={code}
          onChangeText={setCode}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType={useBackup ? 'default' : 'number-pad'}
          maxLength={useBackup ? 24 : 6}
          placeholder={useBackup ? 'XXXX-XXXX-XXXX' : '000000'}
          placeholderTextColor={c.textMuted}
          editable={!submitting}
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
          textAlign="center"
        />

        <TouchableOpacity
          style={[styles.button, { backgroundColor: c.primary, opacity: submitting ? 0.6 : 1 }]}
          onPress={handleSubmit}
          disabled={submitting}
          accessibilityRole="button"
        >
          {submitting ? (
            <ActivityIndicator color={c.primaryText} />
          ) : (
            <Text style={[styles.buttonText, { color: c.primaryText }]}>Verifikasi</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity onPress={() => setUseBackup((v) => !v)} style={styles.linkBtn}>
          <Text style={{ color: c.primary, fontSize: 13 }}>
            {useBackup ? 'Pakai kode authenticator' : 'Pakai kode backup'}
          </Text>
        </TouchableOpacity>

        <TouchableOpacity onPress={onCancel} style={styles.linkBtn}>
          <Text style={{ color: c.textMuted, fontSize: 13 }}>Batal & kembali ke login</Text>
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { flex: 1, padding: 24, justifyContent: 'center' },
  title: { fontSize: 22, fontWeight: '700', marginBottom: 8 },
  subtitle: { fontSize: 14, marginBottom: 24 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 14,
    fontSize: 24,
    letterSpacing: 4,
  },
  button: {
    marginTop: 20,
    borderRadius: 8,
    paddingVertical: 14,
    alignItems: 'center',
  },
  buttonText: { fontSize: 16, fontWeight: '600' },
  linkBtn: { alignItems: 'center', paddingVertical: 12 },
});