import React, { useState } from 'react';
import { StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useQuery } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { useAuthStore } from '@store/auth';
import { AttendanceScreen } from '@features/attendance/AttendanceScreen';
import { LeaveModule } from '@features/leave';
import { PayslipScreen } from '@features/payslip/PayslipScreen';
import { ApprovalsScreen } from '@features/approvals/ApprovalsScreen';
import { MoreMenu } from './MoreMenu';
import { ApprovalsApi } from '@features/approvals/api';
import { NotificationsApi } from '@features/notifications/api';
import { t } from '@/i18n';

/**
 * MainTabs — bottom tab nav untuk authenticated user.
 *
 * Tab order based on usage frequency dari MOBILE_APP_PLAN.md:
 *   1. Presensi (paling sering, daily)
 *   2. Cuti
 *   3. Inbox approval (only show kalau user adalah approver)
 *   4. Slip gaji (monthly)
 *   5. Profil
 *
 * Implementation simple: useState + conditional render. Tidak pakai
 * @react-navigation untuk Tier 1 supaya bundle ringan; bisa upgrade
 * ke navigation library kalau butuh deep-link / nested stack.
 *
 * M5: label di-resolve via `t()` runtime, jadi switch bahasa di Settings
 * langsung kelihatan di tab bar (tab bar re-render karena MainTabs
 * mount-ulang setelah preference save — atau tunggu next active focus).
 */
type Tab = 'attendance' | 'leave' | 'approvals' | 'payslip' | 'more';

const buildTabs = (): Array<{ key: Tab; label: string; icon: string }> => [
  { key: 'attendance', label: t('tab.attendance'), icon: '🕒' },
  { key: 'leave',      label: t('tab.leave'),      icon: '🌴' },
  { key: 'approvals',  label: t('tab.approvals'),  icon: '✅' },
  { key: 'payslip',    label: t('tab.payslip'),    icon: '💰' },
  { key: 'more',       label: t('tab.more'),       icon: '☰' },
];

export const MainTabs: React.FC = () => {
  const theme = useTheme();
  const user = useAuthStore((s) => s.user);
  const [active, setActive] = useState<Tab>('attendance');

  // Approver ditentukan dari role_id !== employee biasa.
  // Backend akan tetap enforce permission di endpoint approval.
  // Untuk MVP, tampilkan tab approvals untuk semua kecuali pure-employee.
  const isApprover = (user?.roleId ?? 0) !== 0; // simplistic; refine dgn permission

  // Badge counters di tab — refresh tiap 60s tanpa block UI
  const inboxCountQ = useQuery({
    queryKey: ['approvals.inbox.count'],
    queryFn: async () => (await ApprovalsApi.inbox()).length,
    enabled: isApprover,
    refetchInterval: 60_000,
    staleTime: 30_000,
  });
  const unreadNotifQ = useQuery({
    queryKey: ['notifications.unread'],
    queryFn: async () => (await NotificationsApi.unreadCount()).count,
    refetchInterval: 60_000,
    staleTime: 30_000,
  });

  const badgeFor = (tab: Tab): number => {
    if (tab === 'approvals') return inboxCountQ.data ?? 0;
    if (tab === 'more')      return unreadNotifQ.data ?? 0;
    return 0;
  };

  const visibleTabs = buildTabs().filter((tab) => tab.key !== 'approvals' || isApprover);

  const renderActive = () => {
    switch (active) {
      case 'attendance': return <AttendanceScreen />;
      case 'leave':      return <LeaveModule />;
      case 'approvals':  return <ApprovalsScreen />;
      case 'payslip':    return <PayslipScreen />;
      case 'more':       return <MoreMenu />;
    }
  };

  const c = theme.colors;
  return (
    <View style={[styles.root, { backgroundColor: c.bg }]}>
      <View style={styles.body}>{renderActive()}</View>
      <SafeAreaView edges={['bottom']} style={[styles.tabBar, { backgroundColor: c.surface, borderColor: c.border }]}>
        <View style={styles.tabRow}>
          {visibleTabs.map((t) => {
            const isActive = active === t.key;
            const badge = badgeFor(t.key);
            return (
              <TouchableOpacity
                key={t.key}
                onPress={() => setActive(t.key)}
                style={styles.tabBtn}
                accessibilityRole="tab"
                accessibilityState={{ selected: isActive }}
                accessibilityLabel={badge > 0 ? `${t.label}, ${badge} item baru` : t.label}
              >
                <View>
                  <Text style={[styles.tabIcon, { opacity: isActive ? 1 : 0.5 }]}>{t.icon}</Text>
                  {badge > 0 && (
                    <View style={[styles.badge, { backgroundColor: c.danger }]}>
                      <Text style={styles.badgeText}>{badge > 99 ? '99+' : String(badge)}</Text>
                    </View>
                  )}
                </View>
                <Text style={[styles.tabLabel, { color: isActive ? c.primary : c.textMuted }]}>
                  {t.label}
                </Text>
              </TouchableOpacity>
            );
          })}
        </View>
      </SafeAreaView>
    </View>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  body: { flex: 1 },
  tabBar: {
    borderTopWidth: StyleSheet.hairlineWidth,
  },
  tabRow: {
    flexDirection: 'row',
    paddingTop: 6,
  },
  tabBtn: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: 6,
  },
  tabIcon: { fontSize: 22, marginBottom: 2 },
  tabLabel: { fontSize: 11, fontWeight: '600' },
  badge: {
    position: 'absolute',
    top: -2,
    right: -10,
    minWidth: 18,
    height: 18,
    paddingHorizontal: 4,
    borderRadius: 9,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { color: '#fff', fontSize: 10, fontWeight: '700' },
});
