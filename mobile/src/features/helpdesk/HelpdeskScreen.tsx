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
import { HelpdeskApi, type HelpdeskCreateInput, type HelpdeskTicket } from './api';
import { ApiError } from '@api/client';

const STATUS_LABEL: Record<HelpdeskTicket['status'], string> = {
  OPEN:        'Open',
  IN_PROGRESS: 'Diproses',
  RESOLVED:    'Selesai',
  CLOSED:      'Tutup',
  REOPENED:    'Reopened',
};

const PRIORITY_COLORS: Record<HelpdeskTicket['priority'], 'success' | 'warning' | 'danger' | 'textMuted'> = {
  LOW:    'textMuted',
  NORMAL: 'success',
  HIGH:   'warning',
  URGENT: 'danger',
};

const CATEGORIES = ['IT', 'HR', 'Finance', 'GA', 'Other'];

/**
 * Helpdesk — Tier 2.4.
 * Submit ticket ke IT/HR/Finance/GA. Backend HelpdeskController sudah
 * lengkap (`POST /helpdesk/tickets`). MVP hanya list + create; comment
 * thread + status change di-defer.
 */
export const HelpdeskScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<HelpdeskCreateInput>({
    subject: '',
    category: 'IT',
    priority: 'NORMAL',
    description: '',
  });

  const listQ = useQuery({ queryKey: ['helpdesk.list'], queryFn: HelpdeskApi.list });

  const submitMut = useMutation({
    mutationFn: (input: HelpdeskCreateInput) => HelpdeskApi.create(input),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Ticket dibuat');
      setShowForm(false);
      setForm({ subject: '', category: 'IT', priority: 'NORMAL', description: '' });
      queryClient.invalidateQueries({ queryKey: ['helpdesk.list'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal membuat ticket';
      Alert.alert('Error', msg);
    },
  });

  const handleSubmit = () => {
    if (!form.subject.trim() || form.subject.length < 5) {
      Alert.alert('Validasi', 'Subjek minimal 5 karakter');
      return;
    }
    if (!form.description.trim() || form.description.length < 10) {
      Alert.alert('Validasi', 'Deskripsi minimal 10 karakter');
      return;
    }
    submitMut.mutate({
      ...form,
      subject: form.subject.trim(),
      description: form.description.trim(),
    });
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={listQ.isRefetching} onRefresh={listQ.refetch} />}
      >
        <View style={styles.headerRow}>
          <Text style={[styles.h1, { color: c.text }]}>Helpdesk</Text>
          <TouchableOpacity
            style={[styles.cta, { backgroundColor: c.primary }]}
            onPress={() => setShowForm((v) => !v)}
          >
            <Text style={[styles.ctaText, { color: c.primaryText }]}>
              {showForm ? 'Tutup' : '+ Ticket'}
            </Text>
          </TouchableOpacity>
        </View>

        {showForm && (
          <View style={[styles.formCard, { backgroundColor: c.surface, borderColor: c.border }]}>
            <Text style={[styles.label, { color: c.text }]}>Subjek</Text>
            <TextInput
              value={form.subject}
              onChangeText={(v) => setForm({ ...form, subject: v })}
              placeholder="Cth: Akun email tidak bisa diakses"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <Text style={[styles.label, { color: c.text }]}>Kategori</Text>
            <View style={styles.chipRow}>
              {CATEGORIES.map((cat) => {
                const sel = form.category === cat;
                return (
                  <TouchableOpacity
                    key={cat}
                    onPress={() => setForm({ ...form, category: cat })}
                    style={[
                      styles.chip,
                      { backgroundColor: sel ? c.primary : c.surfaceAlt, borderColor: sel ? c.primary : c.border },
                    ]}
                  >
                    <Text style={{ color: sel ? c.primaryText : c.text, fontSize: 12 }}>{cat}</Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <Text style={[styles.label, { color: c.text }]}>Prioritas</Text>
            <View style={styles.chipRow}>
              {(['LOW', 'NORMAL', 'HIGH', 'URGENT'] as const).map((p) => {
                const sel = form.priority === p;
                return (
                  <TouchableOpacity
                    key={p}
                    onPress={() => setForm({ ...form, priority: p })}
                    style={[
                      styles.chip,
                      { backgroundColor: sel ? c.primary : c.surfaceAlt, borderColor: sel ? c.primary : c.border },
                    ]}
                  >
                    <Text style={{ color: sel ? c.primaryText : c.text, fontSize: 12 }}>{p}</Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <Text style={[styles.label, { color: c.text }]}>Deskripsi</Text>
            <TextInput
              value={form.description}
              onChangeText={(v) => setForm({ ...form, description: v })}
              placeholder="Jelaskan masalah secara detail..."
              placeholderTextColor={c.textMuted}
              multiline
              numberOfLines={5}
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
                <Text style={[styles.buttonText, { color: c.primaryText }]}>Submit</Text>
              )}
            </TouchableOpacity>
          </View>
        )}

        <Text style={[styles.h2, { color: c.text, marginTop: 16 }]}>Ticket saya</Text>
        {listQ.isLoading && <ActivityIndicator color={c.primary} />}
        {(listQ.data ?? []).map((t) => {
          const pc = c[PRIORITY_COLORS[t.priority]];
          return (
            <View key={t.id} style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
              <View style={styles.cardHead}>
                <Text style={[styles.priorityBadge, { backgroundColor: pc + '20', color: pc }]}>{t.priority}</Text>
                <Text style={[styles.muted, { color: c.textMuted, fontSize: 11 }]}>
                  {t.category} • #{t.id}
                </Text>
              </View>
              <Text style={[styles.subject, { color: c.text }]} numberOfLines={2}>{t.subject}</Text>
              <Text style={[styles.desc, { color: c.textMuted }]} numberOfLines={2}>
                {t.description}
              </Text>
              <View style={styles.footRow}>
                <Text style={[styles.statusText, { color: c.primary }]}>{STATUS_LABEL[t.status]}</Text>
                {t.comments_count != null && t.comments_count > 0 && (
                  <Text style={[styles.muted, { color: c.textMuted }]}>💬 {t.comments_count}</Text>
                )}
              </View>
            </View>
          );
        })}
        {!listQ.isLoading && (listQ.data ?? []).length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada ticket helpdesk.</Text>
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
  muted: { fontSize: 12 },
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
  textarea: { minHeight: 100, textAlignVertical: 'top' },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap' },
  chip: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
    marginRight: 6,
    marginBottom: 6,
  },
  button: { marginTop: 20, borderRadius: 8, paddingVertical: 12, alignItems: 'center' },
  buttonText: { fontSize: 15, fontWeight: '600' },
  card: {
    marginTop: 12,
    padding: 14,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 10,
  },
  cardHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  priorityBadge: {
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 999,
    fontSize: 10,
    fontWeight: '700',
  },
  subject: { fontSize: 15, fontWeight: '600', marginTop: 6 },
  desc: { fontSize: 13, marginTop: 4 },
  footRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 8 },
  statusText: { fontSize: 12, fontWeight: '600' },
  empty: { textAlign: 'center', paddingVertical: 24, fontSize: 13 },
});