import React, { useState } from 'react';
import { ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTheme } from '@theme/ThemeProvider';
import { OvertimeScreen } from '@features/overtime/OvertimeScreen';
import { ReimbursementScreen } from '@features/reimbursement/ReimbursementScreen';
import { HelpdeskScreen } from '@features/helpdesk/HelpdeskScreen';
import { NotificationsScreen } from '@features/notifications/NotificationsScreen';
import { ProfileScreen } from '@features/profile/ProfileScreen';
import { BusinessTripScreen } from '@features/business-trip/BusinessTripScreen';
import { SettingsScreen } from '@features/settings/SettingsScreen';
import { PerformanceScreen } from '@features/performance/PerformanceScreen';
import { TrainingScreen } from '@features/training/TrainingScreen';
import { TasksScreen } from '@features/tasks/TasksScreen';
import { t } from '@/i18n';

/**
 * "More" tab — Tier 2 + Tier 3 modules + Profile.
 *
 * Saat tab "Lainnya" diklik, tampil grid menu untuk pilih modul.
 * Pilihan dirender di-place (tidak push navigator) untuk konsistensi
 * dengan pola lain dan supaya bundle ringan.
 *
 * M4: tambah Performance, Training, dan Tasks (onboarding/offboarding).
 * M5: label di-resolve via i18n `t()` runtime.
 */
type Sub =
  | 'menu'
  | 'overtime'
  | 'reimburse'
  | 'helpdesk'
  | 'notif'
  | 'profile'
  | 'businesstrip'
  | 'settings'
  | 'performance'
  | 'training'
  | 'tasks';

interface MenuItem {
  key: Exclude<Sub, 'menu'>;
  label: string;
  icon: string;
  description: string;
}

const buildMenu = (): MenuItem[] => [
  { key: 'overtime',     label: t('more.overtime'),     icon: '⏱️', description: 'Ajukan & lihat riwayat lembur' },
  { key: 'reimburse',    label: t('more.reimburse'),    icon: '🧾', description: 'Klaim biaya operasional' },
  { key: 'businesstrip', label: t('more.businesstrip'), icon: '✈️', description: 'Perjalanan dinas & SPPD' },
  { key: 'helpdesk',     label: t('more.helpdesk'),     icon: '🎫', description: 'Submit ticket IT/HR/Finance' },
  { key: 'tasks',        label: t('more.tasks'),        icon: '✅', description: 'Onboarding / offboarding' },
  { key: 'performance',  label: t('more.performance'),  icon: '🎯', description: 'Goals + check-in + reviews' },
  { key: 'training',     label: t('more.training'),     icon: '🎓', description: 'Katalog & sertifikat' },
  { key: 'notif',        label: t('more.notif'),        icon: '🔔', description: 'Pengumuman & alert' },
  { key: 'profile',      label: t('more.profile'),      icon: '👤', description: 'Akun & avatar' },
  { key: 'settings',     label: t('more.settings'),     icon: '⚙️', description: 'Password, 2FA, biometrik' },
];

export const MoreMenu: React.FC = () => {
  const theme = useTheme();
  const [sub, setSub] = useState<Sub>('menu');

  if (sub === 'overtime')     return <SubWrapper onBack={() => setSub('menu')} screen={<OvertimeScreen />} />;
  if (sub === 'reimburse')    return <SubWrapper onBack={() => setSub('menu')} screen={<ReimbursementScreen />} />;
  if (sub === 'businesstrip') return <SubWrapper onBack={() => setSub('menu')} screen={<BusinessTripScreen />} />;
  if (sub === 'helpdesk')     return <SubWrapper onBack={() => setSub('menu')} screen={<HelpdeskScreen />} />;
  if (sub === 'notif')        return <SubWrapper onBack={() => setSub('menu')} screen={<NotificationsScreen />} />;
  if (sub === 'profile')      return <SubWrapper onBack={() => setSub('menu')} screen={<ProfileScreen />} />;
  if (sub === 'settings')     return <SubWrapper onBack={() => setSub('menu')} screen={<SettingsScreen onClose={() => setSub('menu')} />} />;
  if (sub === 'performance')  return <SubWrapper onBack={() => setSub('menu')} screen={<PerformanceScreen />} />;
  if (sub === 'training')     return <SubWrapper onBack={() => setSub('menu')} screen={<TrainingScreen />} />;
  if (sub === 'tasks')        return <SubWrapper onBack={() => setSub('menu')} screen={<TasksScreen />} />;

  const c = theme.colors;
  const menu = buildMenu();
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.h1, { color: c.text }]}>{t('more.title')}</Text>
        <Text style={[styles.muted, { color: c.textMuted }]}>{t('more.subtitle')}</Text>
        <View style={styles.grid}>
          {menu.map((m) => (
            <TouchableOpacity
              key={m.key}
              onPress={() => setSub(m.key)}
              style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
              accessibilityRole="button"
              accessibilityLabel={m.label}
            >
              <Text style={styles.icon}>{m.icon}</Text>
              <Text style={[styles.cardLabel, { color: c.text }]}>{m.label}</Text>
              <Text style={[styles.cardDesc, { color: c.textMuted }]} numberOfLines={2}>
                {m.description}
              </Text>
            </TouchableOpacity>
          ))}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
};

const SubWrapper: React.FC<{ onBack: () => void; screen: React.ReactNode }> = ({ onBack, screen }) => {
  const theme = useTheme();
  const c = theme.colors;
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <SafeAreaView edges={['top']}>
        <TouchableOpacity onPress={onBack} style={styles.backBtn}>
          <Text style={{ color: c.primary, fontWeight: '600' }}>
            ← {t('common.back')}
          </Text>
        </TouchableOpacity>
      </SafeAreaView>
      <View style={{ flex: 1 }}>{screen}</View>
    </View>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  muted: { fontSize: 13, marginTop: 4, marginBottom: 16 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  card: {
    width: '48%',
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    marginBottom: 12,
  },
  icon: { fontSize: 28, marginBottom: 8 },
  cardLabel: { fontSize: 15, fontWeight: '600' },
  cardDesc: { fontSize: 12, marginTop: 4 },
  backBtn: { paddingHorizontal: 16, paddingVertical: 8 },
});