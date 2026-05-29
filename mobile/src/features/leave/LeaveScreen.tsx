import React from 'react';
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useQuery, useQueryClient } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { useAuthStore } from '@store/auth';
import { LeaveApi, type LeaveRecord } from './api';

const STATUS_COLORS: Record<LeaveRecord['status'], 'success' | 'warning' | 'danger' | 'textMuted'> = {
  APPROVED: 'success',
  PENDING: 'warning',
  REJECTED: 'danger',
  CANCELLED: 'textMuted',
};

interface Props {
  onCreate: () => void;
}

/**
 * Leave home — Tier 1.3.
 * Tampilan: ringkasan saldo per leave_type + riwayat pengajuan.
 * Tombol "Ajukan cuti" buka LeaveCreateScreen.
 */
export const LeaveScreen: React.FC<Props> = ({ onCreate }) => {
  const theme = useTheme();
  const employeeId = useAuthStore((s) => s.user?.employeeId ?? 0);
  const queryClient = useQueryClient();

  const balanceQ = useQuery({
    queryKey: ['leave.balance', employeeId],
    queryFn: () => LeaveApi.balance(employeeId),
    enabled: employeeId > 0,
  });
  const myQ = useQuery({
    queryKey: ['leave.my'],
    queryFn: () => LeaveApi.my(),
  });

  const refreshing = balanceQ.isRefetching || myQ.isRefetching;
  const refetch = () => {
    balanceQ.refetch();
    myQ.refetch();
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refetch} />}
      >
        <View style={styles.headerRow}>
          <Text style={[styles.h1, { color: c.text }]}>Cuti</Text>
          <TouchableOpacity
            style={[styles.cta, { backgroundColor: c.primary }]}
            onPress={onCreate}
            accessibilityRole="button"
            accessibilityLabel="Ajukan cuti baru"
          >
            <Text style={[styles.ctaText, { color: c.primaryText }]}>+ Ajukan</Text>
          </TouchableOpacity>
        </View>

        {/* Saldo */}
        <Text style={[styles.h2, { color: c.text }]}>Saldo</Text>
        {balanceQ.isLoading ? (
          <ActivityIndicator color={c.primary} />
        ) : (
          (balanceQ.data ?? []).map((b) => (
            <View key={b.leave_type_id} style={[styles.balanceRow, { borderColor: c.border }]}>
              <View style={{ flex: 1 }}>
                <Text style={[styles.bName, { color: c.text }]}>{b.leave_type}</Text>
                <Text style={[styles.bSub, { color: c.textMuted }]}>
                  Quota {b.quota} • Terpakai {b.used}
                  {b.pending > 0 ? ` • Pending ${b.pending}` : ''}
                </Text>
              </View>
              <Text style={[styles.bAvail, { color: b.available > 0 ? c.success : c.danger }]}>
                {b.available}
              </Text>
            </View>
          ))
        )}

        {/* Riwayat */}
        <Text style={[styles.h2, { color: c.text, marginTop: 24 }]}>Riwayat pengajuan</Text>
        {myQ.isLoading ? (
          <ActivityIndicator color={c.primary} />
        ) : (myQ.data ?? []).length === 0 ? (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada pengajuan cuti.</Text>
        ) : (
          (myQ.data ?? []).map((r) => {
            const sc = c[STATUS_COLORS[r.status]];
            return (
              <View key={r.id} style={[styles.historyRow, { borderColor: c.border }]}>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.bName, { color: c.text }]}>{r.leave_type}</Text>
                  <Text style={[styles.bSub, { color: c.textMuted }]}>
                    {r.start_date} — {r.end_date} ({r.days} hari)
                  </Text>
                  {r.reason ? (
                    <Text style={[styles.bSub, { color: c.textMuted }]} numberOfLines={2}>
                      {r.reason}
                    </Text>
                  ) : null}
                </View>
                <Text style={[styles.statusPill, { color: sc }]}>{r.status}</Text>
              </View>
            );
          })
        )}
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  h1: { fontSize: 24, fontWeight: '700' },
  h2: { fontSize: 16, fontWeight: '600', marginTop: 16, marginBottom: 8 },
  cta: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8 },
  ctaText: { fontWeight: '600', fontSize: 14 },
  balanceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  historyRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  bName: { fontSize: 15, fontWeight: '500' },
  bSub: { fontSize: 12, marginTop: 2 },
  bAvail: { fontSize: 22, fontWeight: '700' },
  statusPill: { fontSize: 11, fontWeight: '700' },
  empty: { textAlign: 'center', paddingVertical: 24, fontSize: 13 },
});