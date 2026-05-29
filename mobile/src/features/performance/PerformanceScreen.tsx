import React, { useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Modal,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { ApiError } from '@api/client';
import {
  PerformanceApi,
  type CheckInInput,
  type PerformanceGoal,
  type PerformanceReview,
} from './api';

/**
 * PerformanceScreen — Tier 3 (read-mostly + check-in).
 *
 * UI plan:
 *   Header
 *   ──────
 *   "Performance" + cycle filter dropdown (default: aktif)
 *
 *   Goals
 *   ─────
 *   Card per goal: title, weight%, progress bar, due date, last check-in,
 *   tombol "Check-in" → modal (slider progress + note).
 *
 *   Reviews
 *   ───────
 *   Card per review: cycle name, status, overall rating + label.
 *   Kalau status SUBMITTED/FINALIZED dan belum di-acknowledge → tombol "Setuju"
 *   yang call /performance-reviews/{id}/acknowledge.
 *
 * Tidak menyediakan create-goal atau submit-review (form panjang →
 * web-only). Mobile cuma fast-path: lihat + check-in + acknowledge.
 */

const STATUS_TONE: Record<PerformanceGoal['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  DRAFT:    'textMuted',
  PENDING:  'warning',
  APPROVED: 'primary',
  REJECTED: 'danger',
  ACHIEVED: 'success',
  MISSED:   'danger',
};

const REVIEW_TONE: Record<PerformanceReview['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  DRAFT:        'textMuted',
  IN_PROGRESS:  'warning',
  SUBMITTED:    'primary',
  ACKNOWLEDGED: 'success',
  FINALIZED:    'success',
};

export const PerformanceScreen: React.FC = () => {
  const theme = useTheme();
  const c = theme.colors;
  const qc = useQueryClient();

  const myQ = useQuery({
    queryKey: ['performance.my'],
    queryFn: () => PerformanceApi.my(),
  });

  const [checkInGoal, setCheckInGoal] = useState<PerformanceGoal | null>(null);
  const [progressInput, setProgressInput] = useState<string>('0');
  const [noteInput, setNoteInput] = useState<string>('');

  const checkInMut = useMutation({
    mutationFn: ({ goalId, input }: { goalId: number; input: CheckInInput }) =>
      PerformanceApi.checkIn(goalId, input),
    onSuccess: () => {
      Alert.alert('Tersimpan', 'Check-in goal berhasil');
      setCheckInGoal(null);
      setProgressInput('0');
      setNoteInput('');
      qc.invalidateQueries({ queryKey: ['performance.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal menyimpan check-in';
      Alert.alert('Error', msg);
    },
  });

  const ackMut = useMutation({
    mutationFn: (reviewId: number) => PerformanceApi.acknowledgeReview(reviewId),
    onSuccess: () => {
      Alert.alert('Terkonfirmasi', 'Review sudah Anda setujui');
      qc.invalidateQueries({ queryKey: ['performance.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal acknowledge review';
      Alert.alert('Error', msg);
    },
  });

  const goals = myQ.data?.goals ?? [];
  const reviews = myQ.data?.reviews ?? [];

  const openCheckIn = (g: PerformanceGoal) => {
    setCheckInGoal(g);
    setProgressInput(String(g.progress ?? 0));
    setNoteInput('');
  };

  const submitCheckIn = () => {
    if (!checkInGoal) return;
    const p = Number(progressInput);
    if (!Number.isFinite(p) || p < 0 || p > 100) {
      Alert.alert('Validasi', 'Progress harus 0–100');
      return;
    }
    if (noteInput.length > 280) {
      Alert.alert('Validasi', 'Catatan maksimal 280 karakter');
      return;
    }
    checkInMut.mutate({
      goalId: checkInGoal.id,
      input: { progress: p, note: noteInput.trim() || undefined },
    });
  };

  const cycleSummary = useMemo(() => {
    if (goals.length === 0) return '';
    const cycle = goals[0]?.cycle_name ?? '';
    return cycle;
  }, [goals]);

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={myQ.isRefetching} onRefresh={myQ.refetch} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Performance</Text>
        {!!cycleSummary && (
          <Text style={[styles.muted, { color: c.textMuted }]}>Cycle: {cycleSummary}</Text>
        )}

        <Text style={[styles.h2, { color: c.text }]}>Goals saya</Text>
        {myQ.isLoading && <ActivityIndicator color={c.primary} />}
        {!myQ.isLoading && goals.length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>
            Belum ada goal di cycle aktif.
          </Text>
        )}
        {goals.map((g) => {
          const tone = c[STATUS_TONE[g.status] ?? 'textMuted'];
          const pct = Math.max(0, Math.min(100, Number(g.progress) || 0));
          return (
            <View
              key={g.id}
              style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
            >
              <View style={styles.rowTop}>
                <Text style={[styles.cardTitle, { color: c.text }]} numberOfLines={2}>
                  {g.title}
                </Text>
                <Text style={[styles.statusPill, { color: tone }]}>{g.status}</Text>
              </View>
              {!!g.description && (
                <Text style={[styles.cardSub, { color: c.textMuted }]} numberOfLines={2}>
                  {g.description}
                </Text>
              )}
              <View style={styles.metaRow}>
                <Text style={[styles.metaText, { color: c.textMuted }]}>
                  Bobot {g.weight}% • Due {g.due_date ?? '—'}
                </Text>
              </View>
              <View style={[styles.progressTrack, { backgroundColor: c.surfaceAlt }]}>
                <View
                  style={[
                    styles.progressFill,
                    { backgroundColor: c.primary, width: `${pct}%` },
                  ]}
                />
              </View>
              <Text style={[styles.progressLabel, { color: c.textMuted }]}>
                {pct}% • Last check-in {g.last_checkin_at ?? 'belum ada'}
              </Text>
              <TouchableOpacity
                style={[styles.cta, { borderColor: c.primary }]}
                onPress={() => openCheckIn(g)}
                disabled={g.status === 'ACHIEVED' || g.status === 'MISSED'}
              >
                <Text style={[styles.ctaText, { color: c.primary }]}>Check-in</Text>
              </TouchableOpacity>
            </View>
          );
        })}

        <Text style={[styles.h2, { color: c.text, marginTop: 20 }]}>Reviews</Text>
        {reviews.length === 0 && !myQ.isLoading && (
          <Text style={[styles.empty, { color: c.textMuted }]}>
            Belum ada review aktif untuk Anda.
          </Text>
        )}
        {reviews.map((r) => {
          const tone = c[REVIEW_TONE[r.status] ?? 'textMuted'];
          const ackable = (r.status === 'SUBMITTED' || r.status === 'FINALIZED') && !r.acknowledged_at;
          return (
            <View
              key={r.id}
              style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
            >
              <View style={styles.rowTop}>
                <Text style={[styles.cardTitle, { color: c.text }]} numberOfLines={1}>
                  {r.cycle_name}
                </Text>
                <Text style={[styles.statusPill, { color: tone }]}>{r.status}</Text>
              </View>
              {r.overall_rating !== null && (
                <Text style={[styles.cardSub, { color: c.textMuted }]}>
                  Overall: {r.overall_rating?.toFixed(2)} {r.overall_label ? `(${r.overall_label})` : ''}
                </Text>
              )}
              {r.acknowledged_at && (
                <Text style={[styles.metaText, { color: c.success }]}>
                  ✓ Disetujui {r.acknowledged_at}
                </Text>
              )}
              {ackable && (
                <TouchableOpacity
                  style={[styles.cta, { borderColor: c.primary }]}
                  onPress={() => ackMut.mutate(r.id)}
                  disabled={ackMut.isPending}
                >
                  <Text style={[styles.ctaText, { color: c.primary }]}>
                    {ackMut.isPending ? 'Memproses…' : 'Setuju & acknowledge'}
                  </Text>
                </TouchableOpacity>
              )}
            </View>
          );
        })}
      </ScrollView>

      <Modal
        visible={!!checkInGoal}
        transparent
        animationType="fade"
        onRequestClose={() => setCheckInGoal(null)}
      >
        <View style={styles.modalBackdrop}>
          <View style={[styles.modalCard, { backgroundColor: c.surface, borderColor: c.border }]}>
            <Text style={[styles.h2, { color: c.text }]}>Check-in goal</Text>
            <Text style={[styles.cardSub, { color: c.textMuted }]} numberOfLines={2}>
              {checkInGoal?.title}
            </Text>
            <Text style={[styles.label, { color: c.text }]}>Progress (%)</Text>
            <TextInput
              value={progressInput}
              onChangeText={setProgressInput}
              keyboardType="number-pad"
              maxLength={3}
              style={[
                styles.input,
                { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
              ]}
            />
            <Text style={[styles.label, { color: c.text }]}>Catatan (opsional)</Text>
            <TextInput
              value={noteInput}
              onChangeText={setNoteInput}
              multiline
              maxLength={280}
              placeholder="Apa yang sudah dikerjakan minggu ini?"
              placeholderTextColor={c.textMuted}
              style={[
                styles.input,
                styles.textarea,
                { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
              ]}
            />
            <View style={styles.modalActions}>
              <TouchableOpacity
                onPress={() => setCheckInGoal(null)}
                style={[styles.btn, { borderColor: c.border }]}
              >
                <Text style={{ color: c.text }}>Batal</Text>
              </TouchableOpacity>
              <TouchableOpacity
                onPress={submitCheckIn}
                disabled={checkInMut.isPending}
                style={[styles.btn, { backgroundColor: c.primary, opacity: checkInMut.isPending ? 0.6 : 1 }]}
              >
                {checkInMut.isPending ? (
                  <ActivityIndicator color={c.primaryText} />
                ) : (
                  <Text style={{ color: c.primaryText, fontWeight: '600' }}>Simpan</Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  h2: { fontSize: 17, fontWeight: '600', marginTop: 14, marginBottom: 8 },
  muted: { fontSize: 13, marginTop: 2, marginBottom: 8 },
  empty: { fontSize: 13, paddingVertical: 16, textAlign: 'center' },
  card: {
    padding: 14,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    marginBottom: 10,
  },
  rowTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  cardTitle: { fontSize: 15, fontWeight: '600', flex: 1, paddingRight: 8 },
  cardSub: { fontSize: 13, marginTop: 4 },
  metaRow: { marginTop: 6 },
  metaText: { fontSize: 12 },
  progressTrack: { height: 8, borderRadius: 4, marginTop: 10, overflow: 'hidden' },
  progressFill: { height: 8, borderRadius: 4 },
  progressLabel: { fontSize: 11, marginTop: 6 },
  statusPill: { fontSize: 11, fontWeight: '700' },
  cta: {
    marginTop: 12,
    paddingVertical: 8,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
    alignItems: 'center',
  },
  ctaText: { fontSize: 13, fontWeight: '600' },
  modalBackdrop: {
    flex: 1,
    backgroundColor: '#0008',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 16,
  },
  modalCard: {
    width: '100%',
    borderRadius: 12,
    borderWidth: StyleSheet.hairlineWidth,
    padding: 16,
  },
  label: { fontSize: 12, fontWeight: '600', marginTop: 12, marginBottom: 4 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
  textarea: { minHeight: 70, textAlignVertical: 'top' },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: 16, gap: 8 },
  btn: {
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
  },
});