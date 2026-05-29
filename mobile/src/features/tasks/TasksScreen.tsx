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
import { ApiError } from '@api/client';
import { TasksApi, type EmployeeTask } from './api';

/**
 * TasksScreen — Onboarding & Offboarding checklist (Tier 3).
 *
 * Karyawan baru: lihat dan tick onboarding tasks (orientation, ambil
 * laptop, sign kontrak, dst.).
 * Karyawan resign: lihat dan tick offboarding tasks (return badge,
 * exit interview, dst.).
 *
 * Backend EmployeeTaskController otomatis filter sesuai role + privacy
 * scope, jadi mobile tinggal panggil index. Filter type ONBOARD vs
 * OFFBOARD via tab.
 */

const STATUS_TONE: Record<EmployeeTask['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  PENDING:     'warning',
  IN_PROGRESS: 'primary',
  COMPLETED:   'success',
  SKIPPED:     'textMuted',
  CANCELLED:   'textMuted',
};

type Filter = 'ONBOARD' | 'OFFBOARD';

export const TasksScreen: React.FC = () => {
  const theme = useTheme();
  const c = theme.colors;
  const qc = useQueryClient();
  const [filter, setFilter] = useState<Filter>('ONBOARD');

  const tasksQ = useQuery({
    queryKey: ['tasks.my', filter],
    queryFn: () => TasksApi.my({ type: filter }),
  });

  const completeMut = useMutation({
    mutationFn: (id: number) => TasksApi.complete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal menyelesaikan task';
      Alert.alert('Error', msg);
    },
  });

  const list = tasksQ.data ?? [];
  const pending = list.filter((t) => t.status === 'PENDING' || t.status === 'IN_PROGRESS');
  const done = list.filter((t) => t.status === 'COMPLETED' || t.status === 'SKIPPED');

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={tasksQ.isRefetching} onRefresh={tasksQ.refetch} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Checklist saya</Text>
        <View style={[styles.tabs, { borderColor: c.border }]}>
          <TouchableOpacity
            style={[styles.tab, filter === 'ONBOARD' && { backgroundColor: c.primary }]}
            onPress={() => setFilter('ONBOARD')}
          >
            <Text style={{ color: filter === 'ONBOARD' ? c.primaryText : c.text, fontWeight: '600' }}>
              Onboarding
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.tab, filter === 'OFFBOARD' && { backgroundColor: c.primary }]}
            onPress={() => setFilter('OFFBOARD')}
          >
            <Text style={{ color: filter === 'OFFBOARD' ? c.primaryText : c.text, fontWeight: '600' }}>
              Offboarding
            </Text>
          </TouchableOpacity>
        </View>

        {tasksQ.isLoading && <ActivityIndicator color={c.primary} />}
        {!tasksQ.isLoading && list.length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>
            Tidak ada task {filter === 'ONBOARD' ? 'onboarding' : 'offboarding'} aktif.
          </Text>
        )}

        {pending.length > 0 && (
          <Text style={[styles.h2, { color: c.text }]}>To-do ({pending.length})</Text>
        )}
        {pending.map((t) => (
          <TaskCard
            key={t.id}
            task={t}
            onComplete={() => completeMut.mutate(t.id)}
            disabled={completeMut.isPending}
            theme={theme}
          />
        ))}

        {done.length > 0 && (
          <>
            <Text style={[styles.h2, { color: c.text, marginTop: 18 }]}>Selesai ({done.length})</Text>
            {done.map((t) => (
              <TaskCard key={t.id} task={t} theme={theme} />
            ))}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
};

interface TaskCardProps {
  task: EmployeeTask;
  onComplete?: () => void;
  disabled?: boolean;
  theme: ReturnType<typeof useTheme>;
}

const TaskCard: React.FC<TaskCardProps> = ({ task, onComplete, disabled, theme }) => {
  const c = theme.colors;
  const tone = c[STATUS_TONE[task.status] ?? 'textMuted'];
  const overdue =
    task.due_date &&
    task.status !== 'COMPLETED' &&
    task.status !== 'SKIPPED' &&
    task.due_date < new Date().toISOString().slice(0, 10);
  return (
    <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
      <View style={styles.rowTop}>
        <Text style={[styles.cardTitle, { color: c.text }]} numberOfLines={2}>
          {task.title}
        </Text>
        <Text style={[styles.statusPill, { color: tone }]}>{task.status}</Text>
      </View>
      {!!task.description && (
        <Text style={[styles.cardSub, { color: c.textMuted }]} numberOfLines={3}>
          {task.description}
        </Text>
      )}
      {!!task.assignee_name && (
        <Text style={[styles.metaText, { color: c.textMuted }]}>
          Assignee: {task.assignee_name}
        </Text>
      )}
      {!!task.due_date && (
        <Text style={[styles.metaText, { color: overdue ? c.danger : c.textMuted }]}>
          Deadline: {task.due_date}{overdue ? ' • OVERDUE' : ''}
        </Text>
      )}
      {!!task.completed_at && (
        <Text style={[styles.metaText, { color: c.success }]}>
          ✓ Selesai {task.completed_at}
        </Text>
      )}
      {onComplete && (
        <TouchableOpacity
          style={[styles.cta, { borderColor: c.primary }]}
          onPress={onComplete}
          disabled={disabled}
        >
          <Text style={[styles.ctaText, { color: c.primary }]}>
            {disabled ? 'Memproses…' : 'Tandai selesai'}
          </Text>
        </TouchableOpacity>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  h2: { fontSize: 16, fontWeight: '600', marginTop: 6, marginBottom: 8 },
  empty: { fontSize: 13, paddingVertical: 16, textAlign: 'center' },
  tabs: {
    flexDirection: 'row',
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
    overflow: 'hidden',
    marginVertical: 12,
  },
  tab: { flex: 1, paddingVertical: 10, alignItems: 'center' },
  card: {
    padding: 14,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    marginBottom: 10,
  },
  rowTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  cardTitle: { fontSize: 15, fontWeight: '600', flex: 1, paddingRight: 8 },
  cardSub: { fontSize: 13, marginTop: 4 },
  metaText: { fontSize: 12, marginTop: 4 },
  statusPill: { fontSize: 11, fontWeight: '700' },
  cta: {
    marginTop: 12,
    paddingVertical: 8,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
    alignItems: 'center',
  },
  ctaText: { fontSize: 13, fontWeight: '600' },
});