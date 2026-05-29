import React, { useState } from 'react';
import { ScrollView, StyleSheet, Switch, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme, useThemeControl, type ThemeMode } from '@theme/ThemeProvider';
import { availableLocales, getLocale, setLocale, t, type Locale } from '@/i18n';
import { IdleLock } from '@/lib/idleLock';
import { Biometric } from '@features/auth/biometric';
import { NotificationPreferencesScreen } from '@features/notifications/NotificationPreferencesScreen';

interface Props { onClose: () => void }

type Sub = 'menu' | 'notif';

/**
 * PreferencesScreen — Sprint M5 + M6.
 *
 * Mengelola preferensi non-keamanan-tinggi:
 *   - Bahasa (id / en) — efek immediate, simpan ke MMKV via setLocale
 *   - Theme mode (system / light / dark) — M6
 *   - Idle auto-lock threshold (menit) — clamp di IdleLock layer
 *   - Biometric toggle (mirror dari TwoFactorManageScreen)
 *   - Sub-route: Notifikasi (per-channel toggle)
 *
 * Catatan: ganti bahasa di-handle dengan force re-render via setState
 * lokal `tick`. Tidak pakai context broadcast karena scope kecil.
 */
export const PreferencesScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const c = theme.colors;
  const themeCtrl = useThemeControl();
  const [, setTick] = useState(0);
  const refresh = () => setTick((n) => n + 1);
  const [sub, setSub] = useState<Sub>('menu');

  const [thresholdMin, setThresholdMin] = useState<string>(() =>
    String(Math.round(IdleLock.getThresholdSec() / 60)),
  );
  const [biometric, setBiometric] = useState<boolean>(Biometric.isEnabled());

  if (sub === 'notif') {
    return <NotificationPreferencesScreen onClose={() => setSub('menu')} />;
  }

  const saveThreshold = () => {
    const n = parseInt(thresholdMin, 10);
    if (Number.isFinite(n) && n > 0) {
      IdleLock.setThresholdSec(n * 60);
      setThresholdMin(String(Math.round(IdleLock.getThresholdSec() / 60)));
    }
  };

  const toggleBiometric = (v: boolean) => {
    Biometric.setEnabled(v);
    setBiometric(Biometric.isEnabled());
  };

  const currentLocale = getLocale();
  const locales = availableLocales();
  const localeLabel: Record<Locale, string> = { id: 'Bahasa Indonesia', en: 'English' };
  const themeModes: Array<{ key: ThemeMode; label: string; sub: string }> = [
    { key: 'system', label: 'Ikuti sistem', sub: 'Pakai pengaturan OS' },
    { key: 'light',  label: 'Terang',       sub: 'Selalu light mode' },
    { key: 'dark',   label: 'Gelap',        sub: 'Selalu dark mode' },
  ];

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.h1, { color: c.text }]}>Preferensi</Text>

        {/* Language */}
        <Text style={[styles.section, { color: c.textMuted }]}>{t('settings.lang')}</Text>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          {locales.map((loc) => {
            const isActive = currentLocale === loc;
            return (
              <TouchableOpacity
                key={loc}
                onPress={() => {
                  setLocale(loc);
                  refresh();
                }}
                style={[styles.row, { borderColor: c.border }]}
              >
                <View style={{ flex: 1 }}>
                  <Text style={[styles.rowLabel, { color: c.text }]}>{localeLabel[loc]}</Text>
                  <Text style={[styles.rowSub, { color: c.textMuted }]}>{loc}</Text>
                </View>
                {isActive && <Text style={{ color: c.primary, fontSize: 18 }}>✓</Text>}
              </TouchableOpacity>
            );
          })}
        </View>

        {/* Theme — M6 */}
        <Text style={[styles.section, { color: c.textMuted }]}>Tema</Text>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          {themeModes.map((m) => {
            const isActive = themeCtrl.mode === m.key;
            return (
              <TouchableOpacity
                key={m.key}
                onPress={() => themeCtrl.setMode(m.key)}
                style={[styles.row, { borderColor: c.border }]}
              >
                <View style={{ flex: 1 }}>
                  <Text style={[styles.rowLabel, { color: c.text }]}>{m.label}</Text>
                  <Text style={[styles.rowSub, { color: c.textMuted }]}>{m.sub}</Text>
                </View>
                {isActive && <Text style={{ color: c.primary, fontSize: 18 }}>✓</Text>}
              </TouchableOpacity>
            );
          })}
        </View>

        {/* Idle threshold */}
        <Text style={[styles.section, { color: c.textMuted }]}>
          {t('settings.idle_threshold')}
        </Text>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <View style={[styles.row, { borderColor: c.border }]}>
            <View style={{ flex: 1 }}>
              <Text style={[styles.rowLabel, { color: c.text }]}>Auto-lock</Text>
              <Text style={[styles.rowSub, { color: c.textMuted }]}>
                Maks. 60 menit. Nonaktif jika biometrik mati.
              </Text>
            </View>
            <TextInput
              value={thresholdMin}
              onChangeText={setThresholdMin}
              onBlur={saveThreshold}
              onSubmitEditing={saveThreshold}
              keyboardType="number-pad"
              maxLength={3}
              style={[
                styles.minutes,
                { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
              ]}
              accessibilityLabel="Auto-lock threshold dalam menit"
            />
          </View>
        </View>

        {/* Biometric */}
        <Text style={[styles.section, { color: c.textMuted }]}>Biometrik</Text>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <View style={[styles.row, { borderColor: c.border }]}>
            <View style={{ flex: 1 }}>
              <Text style={[styles.rowLabel, { color: c.text }]}>Aktifkan biometrik</Text>
              <Text style={[styles.rowSub, { color: c.textMuted }]}>
                Login cepat tanpa input password setiap kali.
              </Text>
            </View>
            <Switch
              value={biometric}
              onValueChange={toggleBiometric}
              accessibilityLabel="Toggle biometrik"
            />
          </View>
        </View>

        {/* Notifications — M6 sub-route */}
        <Text style={[styles.section, { color: c.textMuted }]}>Notifikasi</Text>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <TouchableOpacity onPress={() => setSub('notif')} style={[styles.row, { borderColor: c.border }]}>
            <View style={{ flex: 1 }}>
              <Text style={[styles.rowLabel, { color: c.text }]}>Atur per channel</Text>
              <Text style={[styles.rowSub, { color: c.textMuted }]}>
                Email, WhatsApp, web push. In-app selalu aktif.
              </Text>
            </View>
            <Text style={{ color: c.textMuted, fontSize: 18 }}>›</Text>
          </TouchableOpacity>
        </View>

        <TouchableOpacity onPress={onClose} style={styles.closeBtn}>
          <Text style={{ color: c.textMuted, fontSize: 14 }}>{t('common.close')}</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700', marginBottom: 16 },
  section: { fontSize: 12, marginTop: 12, marginBottom: 6, fontWeight: '600', letterSpacing: 0.4 },
  card: {
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
  minutes: {
    minWidth: 60,
    textAlign: 'center',
    paddingVertical: 8,
    paddingHorizontal: 10,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    fontSize: 15,
  },
  closeBtn: { alignItems: 'center', paddingVertical: 12, marginTop: 12 },
});