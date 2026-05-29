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
import { TrainingApi, type TrainingItem } from './api';

/**
 * TrainingScreen — Tier 3.
 *
 * Tab toggle: Catalog vs Riwayat saya.
 *  - Catalog: training POSTED, tap "Daftar" → register
 *  - Riwayat: training yang sudah pernah didaftar, status PENDING/APPROVED/...
 *  - Certificates list di bawah (kalau ada).
 *
 * Tidak menyediakan create-training (admin task → desktop).
 */

const REG_TONE: Record<string, 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  PENDING:   'warning',
  APPROVED:  'primary',
  REJECTED:  'danger',
  COMPLETED: 'success',
  CANCELLED: 'textMuted',
};

type Tab = 'catalog' | 'history';

export const TrainingScreen: React.FC = () => {
  const theme = useTheme();
  const c = theme.colors;
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('catalog');

  const catalogQ = useQuery({
    queryKey: ['training.catalog'],
    queryFn: TrainingApi.catalog,
    enabled: tab === 'catalog',
  });
  const historyQ = useQuery({
    queryKey: ['training.history'],
    queryFn: TrainingApi.myRegistrations,
    enabled: tab === 'history',
  });
  const certQ = useQuery({
    queryKey: ['training.certs'],
    queryFn: TrainingApi.myCertificates,
  });

  const registerMut = useMutation({
    mutationFn: (id: number) => TrainingApi.register(id),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Pendaftaran terkirim. Tunggu approval atasan.');
      qc.invalidateQueries({ queryKey: ['training.catalog'] });
      qc.invalidateQueries({ queryKey: ['training.history'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mendaftar';
      Alert.alert('Error', msg);
    },
  });

  const cancelMut = useMutation({
    mutationFn: (regId: number) => TrainingApi.cancelRegistration(regId, 'Cancel via mobile'),
    onSuccess: () => {
      Alert.alert('Dibatalkan', 'Pendaftaran sudah dibatalkan.');
      qc.invalidateQueries({ queryKey: ['training.history'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal membatalkan';
      Alert.alert('Error', msg);
    },
  });

  const list = tab === 'catalog' ? (catalogQ.data ?? []) : (historyQ.data ?? []);
  const isLoading = tab === 'catalog' ? catalogQ.isLoading : historyQ.isLoading;
  const refetching = tab === 'catalog' ? catalogQ.isRefetching : historyQ.isRefetching;
  const onRefresh = () => {
    if (tab === 'catalog') catalogQ.refetch();
    else historyQ.refetch();
    certQ.refetch();
  };

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refetching} onRefresh={onRefresh} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Training</Text>
        <View style={[styles.tabs, { borderColor: c.border }]}>
          <TouchableOpacity
            style={[styles.tab, tab === 'catalog' && { backgroundColor: c.primary }]}
            onPress={() => setTab('catalog')}
          >
            <Text style={{ color: tab === 'catalog' ? c.primaryText : c.text, fontWeight: '600' }}>
              Katalog
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.tab, tab === 'history' && { backgroundColor: c.primary }]}
            onPress={() => setTab('history')}
          >
            <Text style={{ color: tab === 'history' ? c.primaryText : c.text, fontWeight: '600' }}>
              Riwayat saya
            </Text>
          </TouchableOpacity>
        </View>

        {isLoading && <ActivityIndicator color={c.primary} />}
        {!isLoading && list.length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>
            {tab === 'catalog' ? 'Belum ada training tersedia.' : 'Anda belum pernah mendaftar training.'}
          </Text>
        )}
        {list.map((t: TrainingItem) => {
          const regStatus = t.registration_status ?? null;
          const tone = regStatus ? c[REG_TONE[regStatus] ?? 'textMuted'] : c.textMuted;
          const canRegister =
            tab === 'catalog' && (!t.registration_id || regStatus === 'CANCELLED' || regStatus === 'REJECTED');
          const canCancel =
            tab === 'history' &&
            t.registration_id &&
            (regStatus === 'PENDING' || regStatus === 'APPROVED');
          return (
            <View
              key={`${t.id}-${t.registration_id ?? 'reg-none'}`}
              style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
            >
              <View style={styles.rowTop}>
                <Text style={[styles.cardTitle, { color: c.text }]} numberOfLines={2}>
                  {t.title}
                </Text>
                {regStatus && (
                  <Text style={[styles.statusPill, { color: tone }]}>{regStatus}</Text>
                )}
              </View>
              {!!t.category && (
                <Text style={[styles.cardSub, { color: c.textMuted }]}>{t.category}</Text>
              )}
              <Text style={[styles.metaText, { color: c.textMuted }]}>
                {t.start_date ?? '—'}{t.end_date ? ` → ${t.end_date}` : ''}
                {t.location ? ` • ${t.location}` : ''}
              </Text>
              {t.capacity !== null && (
                <Text style={[styles.metaText, { color: c.textMuted }]}>
                  Kapasitas {t.registered_count}/{t.capacity}
                </Text>
              )}
              <View style={styles.actions}>
                {canRegister && (
                  <TouchableOpacity
                    style={[styles.cta, { borderColor: c.primary }]}
                    onPress={() => registerMut.mutate(t.id)}
                    disabled={registerMut.isPending}
                  >
                    <Text style={[styles.ctaText, { color: c.primary }]}>
                      {registerMut.isPending ? 'Memproses…' : 'Daftar'}
                    </Text>
                  </TouchableOpacity>
                )}
                {canCancel && t.registration_id && (
                  <TouchableOpacity
                    style={[styles.cta, { borderColor: c.danger }]}
                    onPress={() => cancelMut.mutate(t.registration_id!)}
                    disabled={cancelMut.isPending}
                  >
                    <Text style={[styles.ctaText, { color: c.danger }]}>Batal daftar</Text>
                  </TouchableOpacity>
                )}
              </View>
            </View>
          );
        })}

        <Text style={[styles.h2, { color: c.text, marginTop: 20 }]}>Sertifikat saya</Text>
        {certQ.isLoading && <ActivityIndicator color={c.primary} />}
        {(certQ.data ?? []).length === 0 && !certQ.isLoading && (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada sertifikat tercatat.</Text>
        )}
        {(certQ.data ?? []).map((cert) => (
          <View
            key={cert.id}
            style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
          >
            <Text style={[styles.cardTitle, { color: c.text }]}>{cert.certificate_name}</Text>
            <Text style={[styles.metaText, { color: c.textMuted }]}>
              {cert.issuer ?? '—'} • Terbit {cert.issued_on ?? '—'}
              {cert.expires_on ? ` • Habis ${cert.expires_on}` : ''}
            </Text>
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
  h2: { fontSize: 17, fontWeight: '600', marginBottom: 8 },
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
  actions: { flexDirection: 'row', marginTop: 10, gap: 8 },
  cta: {
    paddingVertical: 8,
    paddingHorizontal: 12,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
  },
  ctaText: { fontSize: 13, fontWeight: '600' },
});