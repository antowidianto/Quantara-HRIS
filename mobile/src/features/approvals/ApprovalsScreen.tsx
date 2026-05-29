import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { ApprovalsApi, type ApprovalInboxItem } from './api';
import { ApiError } from '@api/client';

const fmtDateTime = (iso: string): string => {
  const d = new Date(iso);
  return d.toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
};

/**
 * Approval Inbox — Tier 1.4. Untuk supervisor/manager/HR yang punya
 * tanggung jawab approve leave/OT/MPP/reimbursement. Bulk action di-defer
 * ke Sprint M5; MVP fokus single-action approve/reject.
 */
export const ApprovalsScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [busyId, setBusyId] = useState<number | null>(null);

  const inboxQ = useQuery({
    queryKey: ['approvals.inbox'],
    queryFn: ApprovalsApi.inbox,
    staleTime: 30_000,
  });

  const decideMut = useMutation({
    mutationFn: async (params: { id: number; action: 'approve' | 'reject' }) => {
      if (params.action === 'approve') return ApprovalsApi.approve(params.id);
      return ApprovalsApi.reject(params.id);
    },
    onSuccess: (_, { action }) => {
      Alert.alert('Sukses', action === 'approve' ? 'Disetujui' : 'Ditolak');
      queryClient.invalidateQueries({ queryKey: ['approvals.inbox'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal memproses';
      Alert.alert('Error', msg);
    },
    onSettled: () => setBusyId(null),
  });

  const handleDecide = (item: ApprovalInboxItem, action: 'approve' | 'reject') => {
    Alert.alert(
      action === 'approve' ? 'Setujui?' : 'Tolak?',
      `${item.module.toUpperCase()} dari ${item.requester}\n${item.subject}`,
      [
        { text: 'Batal', style: 'cancel' },
        {
          text: action === 'approve' ? 'Setujui' : 'Tolak',
          style: action === 'reject' ? 'destructive' : 'default',
          onPress: () => {
            setBusyId(item.id);
            decideMut.mutate({ id: item.id, action });
          },
        },
      ],
    );
  };

  const c = theme.colors;
  const items = inboxQ.data ?? [];

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={inboxQ.isRefetching} onRefresh={inboxQ.refetch} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Inbox Approval</Text>
        <Text style={[styles.muted, { color: c.textMuted }]}>
          {items.length} permintaan menunggu keputusan
        </Text>

        {inboxQ.isLoading && <ActivityIndicator color={c.primary} style={{ marginTop: 24 }} />}

        {!inboxQ.isLoading && items.length === 0 && (
          <View style={styles.emptyBox}>
            <Text style={[styles.emptyTitle, { color: c.text }]}>✓ Tidak ada pending</Text>
            <Text style={[styles.muted, { color: c.textMuted }]}>
              Semua permintaan sudah ditangani.
            </Text>
          </View>
        )}

        {items.map((item) => (
          <View key={item.id} style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
            <View style={styles.cardHead}>
              <Text style={[styles.modBadge, { backgroundColor: c.primary + '20', color: c.primary }]}>
                {item.module.toUpperCase()}
              </Text>
              <Text style={[styles.muted, { color: c.textMuted, fontSize: 11 }]}>
                {fmtDateTime(item.requested_at)}
              </Text>
            </View>
            <Text style={[styles.cardSubject, { color: c.text }]} numberOfLines={2}>
              {item.subject}
            </Text>
            <Text style={[styles.muted, { color: c.textMuted }]}>oleh {item.requester}</Text>
            {item.start_date && item.end_date && (
              <Text style={[styles.muted, { color: c.textMuted }]}>
                Periode: {item.start_date} → {item.end_date}
              </Text>
            )}
            {item.amount != null && (
              <Text style={[styles.muted, { color: c.text, fontWeight: '600' }]}>
                Nominal: Rp {item.amount.toLocaleString('id-ID')}
              </Text>
            )}

            <View style={styles.actions}>
              <TouchableOpacity
                style={[styles.actBtn, { backgroundColor: c.danger, opacity: busyId === item.id ? 0.5 : 1 }]}
                disabled={busyId !== null}
                onPress={() => handleDecide(item, 'reject')}
              >
                <Text style={styles.actText}>Tolak</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.actBtn, { backgroundColor: c.success, opacity: busyId === item.id ? 0.5 : 1, marginLeft: 8 }]}
                disabled={busyId !== null}
                onPress={() => handleDecide(item, 'approve')}
              >
                {busyId === item.id ? <ActivityIndicator color="#fff" /> : <Text style={styles.actText}>Setujui</Text>}
              </TouchableOpacity>
            </View>
          </View>
        ))}
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  muted: { fontSize: 13, marginTop: 4 },
  emptyBox: { paddingVertical: 60, alignItems: 'center' },
  emptyTitle: { fontSize: 18, fontWeight: '600', marginBottom: 4 },
  card: {
    marginTop: 12,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  cardHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  modBadge: {
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 999,
    fontSize: 10,
    fontWeight: '700',
  },
  cardSubject: { fontSize: 15, fontWeight: '600', marginTop: 8 },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: 12 },
  actBtn: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8, minWidth: 96, alignItems: 'center' },
  actText: { color: '#fff', fontWeight: '600', fontSize: 14 },
});