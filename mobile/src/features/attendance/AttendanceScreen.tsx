import React, { useCallback, useState } from 'react';
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
import { launchCamera } from 'react-native-image-picker';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import NetInfo from '@react-native-community/netinfo';

import { useTheme } from '@theme/ThemeProvider';
import { AttendanceApi, type ClockPayload } from './api';
import { getCurrentPosition, requestLocationPermission } from './geo';
import { ApiError } from '@api/client';
import { Outbox } from '@api/outbox';

const today = (): string => new Date().toISOString().slice(0, 10);
const fmtTime = (iso: string | null): string => {
  if (!iso) return '-';
  const d = new Date(iso);
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
};

/**
 * Attendance home — Tier 1.2.
 *
 * UX flow:
 *   1. Cek apakah user sudah clock-in hari ini (hit /attendance/my filter today)
 *   2. Tombol besar "Clock-in" / "Clock-out" tergantung state
 *   3. Saat tombol ditekan: minta permission GPS → ambil posisi → buka kamera selfie
 *      → submit (online) atau enqueue ke outbox (offline)
 *   4. Riwayat 30 hari terakhir di list bawah
 */
export const AttendanceScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [submitting, setSubmitting] = useState<'in' | 'out' | null>(null);

  const fromDate = (() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
  })();

  const { data, isLoading, refetch, isRefetching } = useQuery({
    queryKey: ['attendance.my', fromDate],
    queryFn: () => AttendanceApi.my(fromDate, today()),
    staleTime: 60_000,
  });

  const todays = data?.find((r) => r.work_date === today());
  const hasClockIn = Boolean(todays?.clock_in_at);
  const hasClockOut = Boolean(todays?.clock_out_at);

  const handleClock = useCallback(async (kind: 'in' | 'out') => {
    setSubmitting(kind);
    try {
      // 1. Permission GPS
      const granted = await requestLocationPermission();
      if (!granted) {
        Alert.alert('Izin lokasi ditolak', 'Tidak bisa clock-in tanpa GPS.');
        return;
      }

      // 2. Capture posisi
      const pos = await getCurrentPosition();

      // 3. Buka kamera selfie (front)
      const cam = await launchCamera({
        mediaType: 'photo',
        cameraType: 'front',
        quality: 0.7,
        saveToPhotos: false,
      });
      if (cam.didCancel) return;
      if (cam.errorCode) {
        Alert.alert('Kamera', cam.errorMessage ?? 'Gagal buka kamera');
        return;
      }
      const asset = cam.assets?.[0];
      if (!asset?.uri) {
        Alert.alert('Selfie wajib', 'Foto selfie tidak tersedia');
        return;
      }

      // 4. Submit (atau queue offline)
      const net = await NetInfo.fetch();
      const isOnline = Boolean(net.isConnected && net.isInternetReachable !== false);
      const payload: ClockPayload = {
        latitude: pos.latitude,
        longitude: pos.longitude,
        accuracy_meters: Math.round(pos.accuracy),
        client_at: new Date().toISOString(),
      };
      const fn = kind === 'in' ? AttendanceApi.clockIn : AttendanceApi.clockOut;
      const result = await fn(payload, asset.uri, isOnline);
      if (result.queued) {
        Alert.alert('Tersimpan offline',
          'Data clock-' + kind + ' kamu disimpan dan akan dikirim otomatis saat online.');
      } else {
        Alert.alert('Sukses', `Clock-${kind} berhasil`);
      }
      queryClient.invalidateQueries({ queryKey: ['attendance.my'] });
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : (e instanceof Error ? e.message : 'Error');
      Alert.alert('Gagal', msg);
    } finally {
      setSubmitting(null);
    }
  }, [queryClient]);

  const sweepNow = useCallback(async () => {
    const result = await Outbox.sweep();
    if (result.sent > 0) {
      Alert.alert('Sync berhasil', `${result.sent} entri terkirim. Sisa: ${result.remaining}`);
      queryClient.invalidateQueries({ queryKey: ['attendance.my'] });
    } else if (result.remaining > 0) {
      Alert.alert('Belum bisa sync', `${result.remaining} entri masih tertahan (no signal?).`);
    } else {
      Alert.alert('Outbox kosong', 'Tidak ada data pending.');
    }
  }, [queryClient]);

  const c = theme.colors;
  const outboxCount = Outbox.count();

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Presensi</Text>
        <Text style={[styles.muted, { color: c.textMuted }]}>
          {new Date().toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
          })}
        </Text>

        {/* Today summary */}
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <View style={styles.row}>
            <View style={styles.colHalf}>
              <Text style={[styles.label, { color: c.textMuted }]}>Clock-in</Text>
              <Text style={[styles.timeBig, { color: c.text }]}>{fmtTime(todays?.clock_in_at ?? null)}</Text>
            </View>
            <View style={styles.colHalf}>
              <Text style={[styles.label, { color: c.textMuted }]}>Clock-out</Text>
              <Text style={[styles.timeBig, { color: c.text }]}>{fmtTime(todays?.clock_out_at ?? null)}</Text>
            </View>
          </View>

          <View style={styles.actions}>
            {!hasClockIn && (
              <TouchableOpacity
                style={[styles.actionBtn, { backgroundColor: c.primary, opacity: submitting ? 0.6 : 1 }]}
                onPress={() => handleClock('in')}
                disabled={submitting !== null}
              >
                {submitting === 'in' ? <ActivityIndicator color={c.primaryText} /> :
                  <Text style={[styles.actionLabel, { color: c.primaryText }]}>Clock-in</Text>}
              </TouchableOpacity>
            )}
            {hasClockIn && !hasClockOut && (
              <TouchableOpacity
                style={[styles.actionBtn, { backgroundColor: c.success, opacity: submitting ? 0.6 : 1 }]}
                onPress={() => handleClock('out')}
                disabled={submitting !== null}
              >
                {submitting === 'out' ? <ActivityIndicator color="#fff" /> :
                  <Text style={[styles.actionLabel, { color: '#fff' }]}>Clock-out</Text>}
              </TouchableOpacity>
            )}
            {hasClockIn && hasClockOut && (
              <Text style={[styles.muted, { color: c.success, fontWeight: '600' }]}>
                ✓ Selesai untuk hari ini
              </Text>
            )}
          </View>
        </View>

        {outboxCount > 0 && (
          <TouchableOpacity
            style={[styles.warnBox, { backgroundColor: c.warning + '20', borderColor: c.warning }]}
            onPress={sweepNow}
          >
            <Text style={{ color: c.text, fontWeight: '600' }}>
              {outboxCount} entri pending di outbox — tap untuk sync
            </Text>
          </TouchableOpacity>
        )}

        {/* History */}
        <Text style={[styles.h2, { color: c.text }]}>Riwayat 30 hari</Text>
        {isLoading && <ActivityIndicator color={c.primary} />}
        {data?.filter((r) => r.work_date !== today()).slice(0, 30).map((r) => (
          <View key={r.id} style={[styles.row2, { borderColor: c.border }]}>
            <Text style={[styles.dateText, { color: c.text }]}>{r.work_date}</Text>
            <Text style={[styles.muted, { color: c.textMuted }]}>
              {fmtTime(r.clock_in_at)} → {fmtTime(r.clock_out_at)}
            </Text>
            <Text style={[styles.statusPill, { color: r.status === 'PRESENT' ? c.success : c.warning }]}>
              {r.status}
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
  h2: { fontSize: 16, fontWeight: '600', marginTop: 24, marginBottom: 8 },
  muted: { fontSize: 13, marginTop: 4 },
  card: {
    marginTop: 16,
    padding: 20,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  row: { flexDirection: 'row' },
  colHalf: { flex: 1, alignItems: 'center' },
  label: { fontSize: 12, marginBottom: 6 },
  timeBig: { fontSize: 28, fontWeight: '700' },
  actions: { marginTop: 20, alignItems: 'center' },
  actionBtn: {
    width: '100%',
    paddingVertical: 16,
    borderRadius: 10,
    alignItems: 'center',
  },
  actionLabel: { fontSize: 16, fontWeight: '600' },
  warnBox: {
    marginTop: 12,
    padding: 12,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
  },
  row2: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  dateText: { fontSize: 14, fontWeight: '500', flex: 1 },
  statusPill: { fontSize: 11, fontWeight: '600' },
});