import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
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
import { OvertimeApi, type OvertimeCreateInput, type OvertimeRecord } from './api';
import { ApiError } from '@api/client';

const STATUS_COLORS: Record<OvertimeRecord['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  APPROVED:  'success',
  PENDING:   'warning',
  REJECTED:  'danger',
  CANCELLED: 'textMuted',
  REALIZED:  'primary',
};

const todayIso = (): string => new Date().toISOString().slice(0, 10);

/**
 * Overtime — Tier 2.1.
 * UI sederhana: form di atas (date + start/end + reason), list bawah.
 * Backend OvertimeController sudah ada (`POST /overtimes`).
 * Idempotency-Key auto-injected oleh client.ts.
 */
export const OvertimeScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<OvertimeCreateInput>({
    work_date: todayIso(),
    start_time: '17:00',
    end_time: '20:00',
    reason: '',
  });

  const myQ = useQuery({ queryKey: ['overtime.my'], queryFn: OvertimeApi.my });

  const submitMut = useMutation({
    mutationFn: (input: OvertimeCreateInput) => OvertimeApi.create(input),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Pengajuan lembur dikirim');
      setShowForm(false);
      setForm({ work_date: todayIso(), start_time: '17:00', end_time: '20:00', reason: '' });
      queryClient.invalidateQueries({ queryKey: ['overtime.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mengajukan lembur';
      Alert.alert('Error', msg);
    },
  });

  const handleSubmit = () => {
    if (!form.work_date || !form.start_time || !form.end_time) {
      Alert.alert('Validasi', 'Tanggal & jam wajib diisi');
      return;
    }
    if (form.reason.trim().length < 5) {
      Alert.alert('Validasi', 'Alasan minimal 5 karakter');
      return;
    }
    submitMut.mutate({ ...form, reason: form.reason.trim() });
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={myQ.isRefetching} onRefresh={myQ.refetch} />}
      >
        <View style={styles.headerRow}>
          <Text style={[styles.h1, { color: c.text }]}>Lembur</Text>
          <TouchableOpacity
            style={[styles.cta, { backgroundColor: c.primary }]}
            onPress={() => setShowForm((v) => !v)}
          >
            <Text style={[styles.ctaText, { color: c.primaryText }]}>
              {showForm ? 'Tutup' : '+ Ajukan'}
            </Text>
          </TouchableOpacity>
        </View>

        {showForm && (
          <View style={[styles.formCard, { backgroundColor: c.surface, borderColor: c.border }]}>
            <Text style={[styles.label, { color: c.text }]}>Tanggal</Text>
            <TextInput
              value={form.work_date}
              onChangeText={(v) => setForm({ ...form, work_date: v })}
              placeholder="YYYY-MM-DD"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />
            <View style={styles.timeRow}>
              <View style={{ flex: 1, marginRight: 6 }}>
                <Text style={[styles.label, { color: c.text }]}>Mulai</Text>
                <TextInput
                  value={form.start_time}
                  onChangeText={(v) => setForm({ ...form, start_time: v })}
                  placeholder="HH:MM"
                  placeholderTextColor={c.textMuted}
                  style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
                />
              </View>
              <View style={{ flex: 1, marginLeft: 6 }}>
                <Text style={[styles.label, { color: c.text }]}>Selesai</Text>
                <TextInput
                  value={form.end_time}
                  onChangeText={(v) => setForm({ ...form, end_time: v })}
                  placeholder="HH:MM"
                  placeholderTextColor={c.textMuted}
                  style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
                />
              </View>
            </View>
            <Text style={[styles.label, { color: c.text }]}>Alasan</Text>
            <TextInput
              value={form.reason}
              onChangeText={(v) => setForm({ ...form, reason: v })}
              placeholder="Cth: deadline project Q2..."
              placeholderTextColor={c.textMuted}
              multiline
              numberOfLines={3}
              style={[styles.input, styles.textarea, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />
            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary, opacity: submitMut.isPending ? 0.6 : 1 }]}
              onPress={handleSubmit}
              disabled={submitMut.isPending}
            >
              {submitMut.isPending ? (
                <ActivityIndicator color={c.primaryText} />
              ) : (
                <Text style={[styles.buttonText, { color: c.primaryText }]}>Kirim pengajuan</Text>
              )}
            </TouchableOpacity>
          </View>
        )}

        <Text style={[styles.h2, { color: c.text, marginTop: 16 }]}>Riwayat</Text>
        {myQ.isLoading && <ActivityIndicator color={c.primary} />}
        {(myQ.data ?? []).map((r) => {
          const sc = c[STATUS_COLORS[r.status]];
          return (
            <View key={r.id} style={[styles.row, { borderColor: c.border }]}>
              <View style={{ flex: 1 }}>
                <Text style={[styles.bName, { color: c.text }]}>
                  {r.work_date} • {r.start_time}–{r.end_time}
                </Text>
                <Text style={[styles.bSub, { color: c.textMuted }]} numberOfLines={2}>
                  {r.reason}
                </Text>
                <Text style={[styles.bSub, { color: c.textMuted }]}>
                  {r.hours.toFixed(1)} jam
                  {r.estimated_amount ? ` • Rp ${r.estimated_amount.toLocaleString('id-ID')}` : ''}
                </Text>
              </View>
              <Text style={[styles.statusPill, { color: sc }]}>{r.status}</Text>
            </View>
          );
        })}
        {!myQ.isLoading && (myQ.data ?? []).length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada pengajuan lembur.</Text>
        )}
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  h1: { fontSize: 24, fontWeight: '700' },
  h2: { fontSize: 16, fontWeight: '600', marginBottom: 8 },
  cta: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8 },
  ctaText: { fontWeight: '600', fontSize: 14 },
  formCard: {
    marginTop: 12,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  label: { fontSize: 13, fontWeight: '600', marginTop: 12, marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
  textarea: { minHeight: 70, textAlignVertical: 'top' },
  timeRow: { flexDirection: 'row', justifyContent: 'space-between' },
  button: { marginTop: 20, borderRadius: 8, paddingVertical: 12, alignItems: 'center' },
  buttonText: { fontSize: 15, fontWeight: '600' },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  bName: { fontSize: 15, fontWeight: '500' },
  bSub: { fontSize: 12, marginTop: 2 },
  statusPill: { fontSize: 11, fontWeight: '700' },
  empty: { textAlign: 'center', paddingVertical: 24, fontSize: 13 },
});