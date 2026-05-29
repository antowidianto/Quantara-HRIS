import React, { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useMutation } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { SettingsApi, type TwoFactorEnableResult } from './api';
import { ApiError } from '@api/client';
import { Biometric } from '@features/auth/biometric';

interface Props { onClose: () => void }

/**
 * 2FA management — Sprint M2.
 *
 * Flow ENABLE:
 *   1. Tap "Aktifkan 2FA" → backend balas { secret, otpauth_url, backup_codes }
 *   2. Tampil otpauth URL (user copy ke authenticator app, atau scan QR di
 *      mobile lain). QR rendering di-defer; MVP cukup tampilkan secret manual.
 *   3. Backup codes ditampilkan SEKALI — user wajib simpan
 *
 * Flow DISABLE: minta kode 2FA aktif → backend verify → matikan
 *
 * Biometric flag: option terpisah (independent dari TOTP). Stored di MMKV.
 */
export const TwoFactorManageScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const [enableResult, setEnableResult] = useState<TwoFactorEnableResult | null>(null);
  const [disableCode, setDisableCode] = useState('');
  const [bioAvailable, setBioAvailable] = useState(false);
  const [bioEnabled, setBioEnabled] = useState(Biometric.isEnabled());

  useEffect(() => {
    Biometric.capability().then((cap) => setBioAvailable(cap.available));
  }, []);

  const enableMut = useMutation({
    mutationFn: () => SettingsApi.enable2fa(),
    onSuccess: (data) => setEnableResult(data),
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mengaktifkan 2FA';
      Alert.alert('Error', msg);
    },
  });

  const disableMut = useMutation({
    mutationFn: () => SettingsApi.disable2fa(disableCode),
    onSuccess: () => {
      Alert.alert('Berhasil', '2FA dinonaktifkan');
      setDisableCode('');
      setEnableResult(null);
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Kode salah';
      Alert.alert('Error', msg);
    },
  });

  const toggleBiometric = async () => {
    if (bioEnabled) {
      Biometric.setEnabled(false);
      setBioEnabled(false);
      Alert.alert('Biometrik dinonaktifkan');
      return;
    }
    const ok = await Biometric.authenticate('Konfirmasi pengaktifan biometrik');
    if (ok) {
      Biometric.setEnabled(true);
      setBioEnabled(true);
      Alert.alert('Biometrik aktif', 'Login berikutnya bisa pakai sidik jari/Face ID');
    }
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Text style={[styles.h1, { color: c.text }]}>Verifikasi 2 Langkah</Text>

        {/* Biometric */}
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Text style={[styles.cardTitle, { color: c.text }]}>Biometrik</Text>
          {bioAvailable ? (
            <>
              <Text style={[styles.muted, { color: c.textMuted }]}>
                {bioEnabled
                  ? 'Login cepat dengan sidik jari/Face ID aktif.'
                  : 'Aktifkan untuk login cepat tanpa input password setiap kali.'}
              </Text>
              <TouchableOpacity
                style={[styles.button, { backgroundColor: bioEnabled ? c.surfaceAlt : c.primary, borderColor: c.border, borderWidth: bioEnabled ? 1 : 0 }]}
                onPress={toggleBiometric}
              >
                <Text style={[styles.buttonText, { color: bioEnabled ? c.text : c.primaryText }]}>
                  {bioEnabled ? 'Nonaktifkan' : 'Aktifkan'}
                </Text>
              </TouchableOpacity>
            </>
          ) : (
            <Text style={[styles.muted, { color: c.textMuted }]}>
              Sensor biometrik tidak tersedia di device ini.
            </Text>
          )}
        </View>

        {/* TOTP enable result */}
        {enableResult && (
          <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.success }]}>
            <Text style={[styles.cardTitle, { color: c.success }]}>2FA aktif!</Text>
            <Text style={[styles.muted, { color: c.textMuted }]}>
              Tambahkan akun ke authenticator (Google Authenticator, Authy, dll.):
            </Text>
            <View style={[styles.codeBox, { backgroundColor: c.surfaceAlt }]}>
              <Text style={[styles.code, { color: c.text }]} selectable>
                {enableResult.secret}
              </Text>
            </View>
            <Text style={[styles.muted, { color: c.warning, marginTop: 12 }]}>
              ⚠️ Simpan backup codes berikut. Hanya muncul SEKALI:
            </Text>
            <View style={[styles.codeBox, { backgroundColor: c.surfaceAlt }]}>
              {enableResult.backup_codes.map((bc) => (
                <Text key={bc} style={[styles.codeMono, { color: c.text }]} selectable>{bc}</Text>
              ))}
            </View>
          </View>
        )}

        {/* TOTP enable / disable controls */}
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Text style={[styles.cardTitle, { color: c.text }]}>Kode Authenticator (TOTP)</Text>
          <Text style={[styles.muted, { color: c.textMuted }]}>
            Tambahkan login layer kedua dengan aplikasi authenticator.
          </Text>

          {!enableResult && (
            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary, opacity: enableMut.isPending ? 0.6 : 1 }]}
              onPress={() => enableMut.mutate()}
              disabled={enableMut.isPending}
            >
              {enableMut.isPending ? (
                <ActivityIndicator color={c.primaryText} />
              ) : (
                <Text style={[styles.buttonText, { color: c.primaryText }]}>Aktifkan 2FA</Text>
              )}
            </TouchableOpacity>
          )}

          {/* Disable */}
          <Text style={[styles.label, { color: c.text, marginTop: 16 }]}>
            Nonaktifkan 2FA (masukkan kode dari authenticator):
          </Text>
          <TextInput
            value={disableCode}
            onChangeText={setDisableCode}
            keyboardType="number-pad"
            maxLength={6}
            placeholder="000000"
            placeholderTextColor={c.textMuted}
            style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
          />
          <TouchableOpacity
            style={[styles.button, { backgroundColor: c.danger, opacity: disableMut.isPending ? 0.6 : 1 }]}
            onPress={() => disableMut.mutate()}
            disabled={disableMut.isPending || disableCode.length < 6}
          >
            {disableMut.isPending ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={[styles.buttonText, { color: '#fff' }]}>Nonaktifkan</Text>
            )}
          </TouchableOpacity>
        </View>

        <TouchableOpacity onPress={onClose} style={styles.cancelBtn}>
          <Text style={{ color: c.textMuted, fontSize: 14 }}>Tutup</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700', marginBottom: 16 },
  card: {
    marginBottom: 16,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  cardTitle: { fontSize: 16, fontWeight: '600' },
  muted: { fontSize: 13, marginTop: 6, lineHeight: 18 },
  label: { fontSize: 13, fontWeight: '600', marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 18,
    textAlign: 'center',
    letterSpacing: 4,
    marginBottom: 12,
  },
  button: {
    marginTop: 16,
    borderRadius: 8,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonText: { fontSize: 15, fontWeight: '600' },
  codeBox: { padding: 12, borderRadius: 8, marginTop: 8 },
  code: { fontFamily: 'Courier', fontSize: 16, fontWeight: '600' },
  codeMono: { fontFamily: 'Courier', fontSize: 14, lineHeight: 22 },
  cancelBtn: { alignItems: 'center', paddingVertical: 12 },
});