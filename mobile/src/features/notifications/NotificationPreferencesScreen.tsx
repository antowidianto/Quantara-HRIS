import React, { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  ScrollView,
  StyleSheet,
  Switch,
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
  NotificationPrefsApi,
  type NotificationChannel,
  type NotificationPreferenceRow,
  type PreferenceUpdate,
  type QuietHours,
} from './preferencesApi';

/**
 * NotificationPreferencesScreen — Sprint M6.
 *
 * Tabel toggle per (notif_type × channel). Pertimbangan UX:
 *
 *   - "in_app" selalu enabled (backend gate). Toggle disabled +
 *     keterangan "selalu aktif".
 *   - Channel "*" (Default) di paling atas — kalau user matikan WhatsApp
 *     di sini, semua tipe lain default-nya juga matikan WhatsApp kecuali
 *     ada override eksplisit. Backend service yang resolve, mobile cuma
 *     tampilkan + toggle.
 *   - Save mode bulk: kumpulkan delta antara state sekarang vs awal,
 *     kirim sekali pakai PUT. Tidak ada save-per-toggle agar hemat.
 */

interface Props { onClose: () => void }

const CHANNEL_LABEL: Record<NotificationChannel, string> = {
  in_app:   'In-app',
  email:    'Email',
  whatsapp: 'WhatsApp',
  web_push: 'Web push',
};

export const NotificationPreferencesScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const c = theme.colors;
  const qc = useQueryClient();

  const prefsQ = useQuery({
    queryKey: ['notification.prefs'],
    queryFn: NotificationPrefsApi.list,
  });

  // Local mutable copy yang bisa di-edit user. Di-init dari response.
  const [draft, setDraft] = useState<NotificationPreferenceRow[]>([]);

  // M7: quiet hours draft. Sinkron dari prefsQ.data.quiet_hours.
  const [quiet, setQuiet] = useState<QuietHours>({ start: null, end: null });

  useEffect(() => {
    if (prefsQ.data) {
      setDraft(prefsQ.data.preferences);
      if (prefsQ.data.quiet_hours) {
        setQuiet(prefsQ.data.quiet_hours);
      }
    }
  }, [prefsQ.data]);

  const quietMut = useMutation({
    mutationFn: (q: QuietHours) => NotificationPrefsApi.setQuietHours(q.start, q.end),
    onSuccess: (resp) => {
      setQuiet(resp);
      Alert.alert(
        'Tersimpan',
        resp.start && resp.end
          ? `Quiet hours ${resp.start}-${resp.end} aktif`
          : 'Quiet hours dinonaktifkan',
      );
      qc.invalidateQueries({ queryKey: ['notification.prefs'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal menyimpan quiet hours';
      Alert.alert('Error', msg);
    },
  });

  const handleQuietSave = () => {
    // Validasi sederhana: kalau salah satu kosong, treat sebagai OFF.
    const start = quiet.start && /^\d{2}:\d{2}$/.test(quiet.start) ? quiet.start : null;
    const end   = quiet.end   && /^\d{2}:\d{2}$/.test(quiet.end)   ? quiet.end   : null;
    if (quiet.start && !start) {
      Alert.alert('Validasi', 'Format start harus HH:MM');
      return;
    }
    if (quiet.end && !end) {
      Alert.alert('Validasi', 'Format end harus HH:MM');
      return;
    }
    if (!start || !end) {
      quietMut.mutate({ start: null, end: null });
    } else {
      quietMut.mutate({ start, end });
    }
  };

  const handleQuietDisable = () => {
    setQuiet({ start: null, end: null });
    quietMut.mutate({ start: null, end: null });
  };

  const channels = prefsQ.data?.available_channels ?? ['in_app', 'email', 'whatsapp', 'web_push'];
  const types = prefsQ.data?.available_types ?? [];

  // Lookup map untuk effective state — fallback: untuk channel tanpa
  // record explicit, anggap enabled (kecuali in_app yang selalu enabled).
  const isEnabled = (typeKey: string, ch: NotificationChannel): boolean => {
    const row = draft.find((r) => r.notif_type === typeKey);
    if (row && row.channels[ch] !== undefined) {
      return Boolean(row.channels[ch]);
    }
    return true;
  };

  const toggle = (typeKey: string, ch: NotificationChannel, next: boolean) => {
    if (ch === 'in_app') return; // backend reject; UI no-op
    setDraft((prev) => {
      const idx = prev.findIndex((r) => r.notif_type === typeKey);
      if (idx === -1) {
        return [...prev, { notif_type: typeKey, channels: { [ch]: next } }];
      }
      const existing = prev[idx];
      if (!existing) return prev;
      const row: NotificationPreferenceRow = {
        ...existing,
        channels: { ...existing.channels, [ch]: next },
      };
      return [...prev.slice(0, idx), row, ...prev.slice(idx + 1)];
    });
  };

  // Diff vs server state untuk bulk save
  const computeUpdates = (): PreferenceUpdate[] => {
    if (!prefsQ.data) return [];
    const serverMap = new Map<string, NotificationPreferenceRow>();
    for (const r of prefsQ.data.preferences) serverMap.set(r.notif_type, r);

    const updates: PreferenceUpdate[] = [];
    for (const localRow of draft) {
      const serverRow = serverMap.get(localRow.notif_type);
      for (const ch of channels) {
        if (ch === 'in_app') continue;
        const localVal = localRow.channels[ch];
        const serverVal = serverRow?.channels[ch];
        // Kirim hanya kalau berbeda atau server belum punya record
        if (localVal === undefined) continue;
        if (serverVal !== localVal) {
          updates.push({ notif_type: localRow.notif_type, channel: ch, enabled: localVal });
        }
      }
    }
    return updates;
  };

  const saveMut = useMutation({
    mutationFn: (items: PreferenceUpdate[]) => NotificationPrefsApi.update(items),
    onSuccess: (resp) => {
      Alert.alert('Tersimpan', `${resp.updated} preferensi diperbarui`);
      qc.invalidateQueries({ queryKey: ['notification.prefs'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal menyimpan preferensi';
      Alert.alert('Error', msg);
    },
  });

  const updates = useMemo(computeUpdates, [draft, prefsQ.data, channels]);

  const handleSave = () => {
    if (updates.length === 0) {
      Alert.alert('Tidak ada perubahan', 'Belum ada preferensi yang diubah.');
      return;
    }
    saveMut.mutate(updates);
  };

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={[styles.h1, { color: c.text }]}>Notifikasi</Text>
        <Text style={[styles.muted, { color: c.textMuted }]}>
          Atur channel mana yang aktif per kategori. In-app selalu aktif.
        </Text>

        {prefsQ.isLoading && <ActivityIndicator color={c.primary} style={{ marginTop: 24 }} />}

        {/* M7 — Quiet hours card */}
        {!prefsQ.isLoading && (
          <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
            <Text style={[styles.cardTitle, { color: c.text }]}>Jam tenang (DND)</Text>
            <Text style={[styles.metaText, { color: c.textMuted }]}>
              Push notif di-mute selama window berikut. In-app & email tetap masuk.
            </Text>
            <View style={styles.qhRow}>
              <View style={{ flex: 1, marginRight: 8 }}>
                <Text style={[styles.qhLabel, { color: c.textMuted }]}>Mulai</Text>
                <TextInput
                  value={quiet.start ?? ''}
                  onChangeText={(v) => setQuiet((q) => ({ ...q, start: v || null }))}
                  placeholder="22:00"
                  placeholderTextColor={c.textMuted}
                  keyboardType="numbers-and-punctuation"
                  maxLength={5}
                  style={[
                    styles.qhInput,
                    { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
                  ]}
                  accessibilityLabel="Quiet hours start"
                />
              </View>
              <View style={{ flex: 1, marginLeft: 8 }}>
                <Text style={[styles.qhLabel, { color: c.textMuted }]}>Selesai</Text>
                <TextInput
                  value={quiet.end ?? ''}
                  onChangeText={(v) => setQuiet((q) => ({ ...q, end: v || null }))}
                  placeholder="06:00"
                  placeholderTextColor={c.textMuted}
                  keyboardType="numbers-and-punctuation"
                  maxLength={5}
                  style={[
                    styles.qhInput,
                    { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
                  ]}
                  accessibilityLabel="Quiet hours end"
                />
              </View>
            </View>
            <View style={styles.actions}>
              {(quiet.start || quiet.end) && (
                <TouchableOpacity onPress={handleQuietDisable} style={[styles.btn, { borderColor: c.border }]}>
                  <Text style={{ color: c.text }}>Matikan</Text>
                </TouchableOpacity>
              )}
              <TouchableOpacity
                onPress={handleQuietSave}
                disabled={quietMut.isPending}
                style={[
                  styles.btn,
                  { backgroundColor: c.primary, opacity: quietMut.isPending ? 0.6 : 1 },
                ]}
              >
                {quietMut.isPending ? (
                  <ActivityIndicator color={c.primaryText} />
                ) : (
                  <Text style={{ color: c.primaryText, fontWeight: '600' }}>Simpan window</Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        )}

        {!prefsQ.isLoading && types.map((typeMeta) => (
          <View
            key={typeMeta.key}
            style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
          >
            <Text style={[styles.cardTitle, { color: c.text }]}>{typeMeta.label}</Text>
            {channels.map((ch) => {
              const enabled = isEnabled(typeMeta.key, ch);
              const isInApp = ch === 'in_app';
              return (
                <View key={ch} style={[styles.row, { borderColor: c.border }]}>
                  <View style={{ flex: 1 }}>
                    <Text style={[styles.rowLabel, { color: c.text }]}>
                      {CHANNEL_LABEL[ch] ?? ch}
                    </Text>
                    {isInApp && (
                      <Text style={[styles.rowSub, { color: c.textMuted }]}>Selalu aktif</Text>
                    )}
                  </View>
                  <Switch
                    value={isInApp ? true : enabled}
                    disabled={isInApp}
                    onValueChange={(v) => toggle(typeMeta.key, ch, v)}
                    accessibilityLabel={`${CHANNEL_LABEL[ch]} ${typeMeta.label}`}
                  />
                </View>
              );
            })}
          </View>
        ))}

        {!prefsQ.isLoading && types.length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>
            Tidak ada kategori notifikasi tersedia.
          </Text>
        )}

        <View style={styles.actions}>
          <TouchableOpacity onPress={onClose} style={[styles.btn, { borderColor: c.border }]}>
            <Text style={{ color: c.text }}>Tutup</Text>
          </TouchableOpacity>
          <TouchableOpacity
            onPress={handleSave}
            disabled={saveMut.isPending || updates.length === 0}
            style={[
              styles.btn,
              {
                backgroundColor: c.primary,
                opacity: saveMut.isPending || updates.length === 0 ? 0.5 : 1,
              },
            ]}
          >
            {saveMut.isPending ? (
              <ActivityIndicator color={c.primaryText} />
            ) : (
              <Text style={{ color: c.primaryText, fontWeight: '600' }}>
                Simpan {updates.length > 0 ? `(${updates.length})` : ''}
              </Text>
            )}
          </TouchableOpacity>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700' },
  muted: { fontSize: 13, marginTop: 4, marginBottom: 12 },
  empty: { fontSize: 13, paddingVertical: 16, textAlign: 'center' },
  card: {
    padding: 14,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    marginBottom: 10,
  },
  cardTitle: { fontSize: 15, fontWeight: '600', marginBottom: 6 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
  rowLabel: { fontSize: 14, fontWeight: '500' },
  rowSub: { fontSize: 11, marginTop: 2 },
  metaText: { fontSize: 12, marginTop: 4 },
  qhRow: { flexDirection: 'row', marginTop: 10 },
  qhLabel: { fontSize: 11, fontWeight: '600', marginBottom: 4 },
  qhInput: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
    textAlign: 'center',
  },
  actions: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    marginTop: 16,
    gap: 8,
  },
  btn: {
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
  },
});