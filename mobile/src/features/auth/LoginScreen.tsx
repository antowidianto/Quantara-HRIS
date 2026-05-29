import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
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
  onTwoFactor: (challenge: TwoFactorChallenge) => void;
  onForgotPassword: () => void;
}

/**
 * Login screen — Tier 1.1.
 *
 * Field `identifier` accept email atau NIK (sesuai backend AuthController).
 * Tombol "Lupa password" arahkan ke flow forgot-password (deferred ke
 * Sprint M2 berikutnya supaya MVP fokus ke flow utama dulu).
 */
export const LoginScreen: React.FC<Props> = ({ onTwoFactor, onForgotPassword }) => {
  const theme = useTheme();
  const setSession = useAuthStore((s) => s.setSession);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async () => {
    if (!identifier || !password) {
      Alert.alert('Validasi', 'Email/NIK dan password wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      const result = await AuthApi.login(identifier.trim(), password);
      if (result.twoFactorRequired) {
        onTwoFactor(result);
        return;
      }
      await setSession(result.user, result.tokens);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Gagal login. Coba lagi.';
      Alert.alert('Login gagal', msg);
    } finally {
      setSubmitting(false);
    }
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.brandBlock}>
            <Text style={[styles.brand, { color: c.primary }]}>HRIS</Text>
            <Text style={[styles.tagline, { color: c.textMuted }]}>Self-service untuk karyawan</Text>
          </View>

          <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
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

            <Text style={[styles.label, { color: c.text, marginTop: 16 }]}>Password</Text>
            <TextInput
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoCapitalize="none"
              placeholder="••••••••"
              placeholderTextColor={c.textMuted}
              editable={!submitting}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary, opacity: submitting ? 0.6 : 1 }]}
              onPress={handleSubmit}
              disabled={submitting}
              accessibilityRole="button"
              accessibilityLabel="Login"
            >
              {submitting ? (
                <ActivityIndicator color={c.primaryText} />
              ) : (
                <Text style={[styles.buttonText, { color: c.primaryText }]}>Masuk</Text>
              )}
            </TouchableOpacity>

            <TouchableOpacity onPress={onForgotPassword} style={styles.forgotLink}>
              <Text style={{ color: c.primary, fontSize: 13 }}>Lupa password?</Text>
            </TouchableOpacity>
          </View>

          <Text style={[styles.footnote, { color: c.textMuted }]}>
            Butuh bantuan? Hubungi HR via helpdesk@perusahaan.id
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  flex: { flex: 1 },
  content: { flexGrow: 1, padding: 20, justifyContent: 'center' },
  brandBlock: { alignItems: 'center', marginBottom: 28 },
  brand: { fontSize: 36, fontWeight: '700', letterSpacing: 1 },
  tagline: { marginTop: 4, fontSize: 13 },
  card: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    padding: 20,
  },
  label: { fontSize: 13, fontWeight: '600', marginBottom: 6 },
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
  forgotLink: { alignItems: 'center', paddingVertical: 12 },
  footnote: { textAlign: 'center', marginTop: 24, fontSize: 12 },
});
