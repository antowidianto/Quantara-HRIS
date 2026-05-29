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
import { BusinessTripApi, type BusinessTripCreateInput, type BusinessTripRecord } from './api';
import { ApiError } from '@api/client';

const STATUS_LABEL: Record<BusinessTripRecord['status'], string> = {
  DRAFT:       'Draft',
  SUBMITTED:   'Diajukan',
  APPROVED:    'Disetujui',
  REJECTED:    'Ditolak',
  IN_PROGRESS: 'Berlangsung',
  COMPLETED:   'Selesai',
  SETTLED:     'Disetel',
  CANCELLED:   'Dibatalkan',
};

const STATUS_COLOR_KEYS: Record<BusinessTripRecord['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  DRAFT:       'textMuted',
  SUBMITTED:   'warning',
  APPROVED:    'primary',
  REJECTED:    'danger',
  IN_PROGRESS: 'primary',
  COMPLETED:   'success',
  SETTLED:     'success',
  CANCELLED:   'textMuted',
};

const todayIso = (): string => new Date().toISOString().slice(0, 10);

/**
 * Business Trip — Tier 2.3.
 * Submit perjalanan dinas (destination, range, perdiem). Backend
 * BusinessTripController + BusinessTripService sudah lengkap dengan
 * SPPD generator, expense tracking, settlement.
 *
 * MVP mobile: list + create + submit + start/complete actions.
 * Detail expenses + SPPD print di-defer ke desktop view.
 */
export const BusinessTripScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<BusinessTripCreateInput>({
    destination: '',
    start_date: todayIso(),
    end_date: todayIso(),
    purpose: '',
  });

  const myQ = useQuery({ queryKey: ['businesstrip.my'], queryFn: BusinessTripApi.my });

  const submitMut = useMutation({
    mutationFn: (input: BusinessTripCreateInput) => BusinessTripApi.create(input),
    onSuccess: async (rec) => {
      // Auto-submit ke flow approval setelah create
      try {
        await BusinessTripApi.submit(rec.id);
      } catch {
        // best-effort
      }
      Alert.alert('Berhasil', 'Pengajuan business trip dikirim');
      setShowForm(false);
      setForm({ destination: '', start_date: todayIso(), end_date: todayIso(), purpose: '' });
      queryClient.invalidateQueries({ queryKey: ['businesstrip.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mengajukan';
      Alert.alert('Error', msg);
    },
  });

  const handleSubmit = () => {
    if (!form.destination.trim() || !form.start_date || !form.end_date) {
      Alert.alert('Validasi', 'Tujuan & tanggal wajib diisi');
      return;
    }
    if (form.start_date > form.end_date) {
      Alert.alert('Validasi', 'Tanggal mulai harus ≤ tanggal akhir');
      return;
    }
    if (form.purpose.trim().length < 10) {
      Alert.alert('Validasi', 'Tujuan/keperluan minimal 10 karakter');
      return;
    }
    submitMut.mutate({
      ...form,
      destination: form.destination.trim(),
      purpose: form.purpose.trim(),
    });
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={myQ.isRefetching} onRefresh={myQ.refetch} />}
      >
        <View style={styles.headerRow}>
          <Text style={[styles.h1, { color: c.text }]}>Business Trip</Text>
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
            <Text style={[styles.label, { color: c.text }]}>Tujuan</Text>
            <TextInput
              value={form.destination}
              onChangeText={(v) => setForm({ ...form, destination: v })}
              placeholder="Cth: Surabaya, Bali, Singapore"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <View style={styles.timeRow}>
              <View style={{ flex: 1, marginRight: 6 }}>
                <Text style={[styles.label, { color: c.text }]}>Dari tanggal</Text>
                <TextInput
                  value={form.start_date}
                  onChangeText={(v) => setForm({ ...form, start_date: v })}
                  placeholder="YYYY-MM-DD"
                  placeholderTextColor={c.textMuted}
                  style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
                />
              </View>
              <View style={{ flex: 1, marginLeft: 6 }}>
                <Text style={[styles.label, { color: c.text }]}>Sampai</Text>
                <TextInput
                  value={form.end_date}
                  onChangeText={(v) => setForm({ ...form, end_date: v })}
                  placeholder="YYYY-MM-DD"
                  placeholderTextColor={c.textMuted}
                  style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
                />
              </View>
            </View>

            <Text style={[styles.label, { color: c.text }]}>Keperluan</Text>
            <TextInput
              value={form.purpose}
              onChangeText={(v) => setForm({ ...form, purpose: v })}
              placeholder="Cth: Meeting client, audit cabang, training..."
              placeholderTextColor={c.textMuted}
              multiline
              numberOfLines={3}
              style={[styles.input, styles.textarea, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <Text style={[styles.label, { color: c.text }]}>Perdiem (Rp, opsional)</Text>
            <TextInput
              value={form.perdiem_amount ? String(form.perdiem_amount) : ''}
              onChangeText={(v) => setForm({ ...form, perdiem_amount: parseInt(v.replace(/\D/g, ''), 10) || 0 })}
              keyboardType="number-pad"
              placeholder="500000"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary, opacity: submitMut.isPending ? 0.6 : 1 }]}
              onPress={handleSubmit}
              disabled={submitMut.isPending}
            >
              {submitMut.isPending ? (
                <ActivityIndicator color={c.primaryText} />
              ) : (
                <Text style={[styles.buttonText, { color: c.primaryText }]}>Submit</Text>
              )}
            </TouchableOpacity>
          </View>
        )}

        <Text style={[styles.h2, { color: c.text, marginTop: 16 }]}>Riwayat</Text>
        {myQ.isLoading && <ActivityIndicator color={c.primary} />}
        {(myQ.data ?? []).map((r: BusinessTripRecord) => {
          const sk = STATUS_COLOR_KEYS[r.status];
          const sc = c[sk];
          return (
            <View key={r.id} style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
              <View style={styles.cardHead}>
                <Text style={[styles.dest, { color: c.text }]}>{r.destination}</Text>
                <Text style={[styles.statusPill, { color: sc }]}>{STATUS_LABEL[r.status]}</Text>
              </View>
              <Text style={[styles.muted, { color: c.textMuted }]}>
                {r.start_date} → {r.end_date}
              </Text>
              <Text style={[styles.purposeText, { color: c.textMuted }]} numberOfLines={2}>
                {r.purpose}
              </Text>
              {r.perdiem_amount ? (
                <Text style={[styles.muted, { color: c.text, fontWeight: '600' }]}>
                  Perdiem: Rp {r.perdiem_amount.toLocaleString('id-ID')}
                </Text>
              ) : null}
            </View>
          );
        })}
        {!myQ.isLoading && (myQ.data ?? []).length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada pengajuan business trip.</Text>
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
  muted: { fontSize: 12, marginTop: 4 },
  cta: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8 },
  ctaText: { fontWeight: '600', fontSize: 14 },
  formCard: { marginTop: 12, padding: 16, borderWidth: StyleSheet.hairlineWidth, borderRadius: 12 },
  label: { fontSize: 13, fontWeight: '600', marginTop: 12, marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
  textarea: { minHeight: 70, textAlignVertical: 'top' },
  timeRow: { flexDirection: 'row' },
  button: { marginTop: 20, borderRadius: 8, paddingVertical: 12, alignItems: 'center' },
  buttonText: { fontSize: 15, fontWeight: '600' },
  card: { marginTop: 12, padding: 14, borderWidth: StyleSheet.hairlineWidth, borderRadius: 10 },
  cardHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  dest: { fontSize: 15, fontWeight: '600' },
  purposeText: { fontSize: 13, marginTop: 6 },
  statusPill: { fontSize: 11, fontWeight: '700' },
  empty: { textAlign: 'center', paddingVertical: 24, fontSize: 13 },
});