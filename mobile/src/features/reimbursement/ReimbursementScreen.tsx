import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Image,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { launchCamera, launchImageLibrary } from 'react-native-image-picker';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { ReimbursementApi, type ReimbursementCreateInput, type ReimbursementRecord } from './api';
import { ApiError } from '@api/client';

const STATUS_COLORS: Record<ReimbursementRecord['status'], 'success' | 'warning' | 'danger' | 'textMuted' | 'primary'> = {
  APPROVED:  'primary',
  PENDING:   'warning',
  REJECTED:  'danger',
  PAID:      'success',
  CANCELLED: 'textMuted',
};

const todayIso = (): string => new Date().toISOString().slice(0, 10);
const fmtRupiah = (n: number): string => 'Rp ' + n.toLocaleString('id-ID');

const CATEGORIES = ['transport', 'meal', 'medical', 'lodging', 'training', 'other'];

/**
 * Reimbursement — Tier 2.2.
 *
 * UX flow:
 *   1. Tap "+ Ajukan" → buka kamera (atau library kalau struk sudah disimpan)
 *   2. Upload struk → backend balas attachment_path
 *   3. UI tampil form pre-filled (vendor, total, date) — kalau backend OCR
 *      sudah aktif, hasil OCR di-extract setelah submit awal.
 *   4. User edit/koreksi → submit final
 *
 * Backend:
 *   - POST /upload/attachment?context=reimbursement
 *   - POST /reimbursements
 *   - POST /reimbursements/{id}/ocr-extract (Horizon2 H2.8)
 */
export const ReimbursementScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [receiptUri, setReceiptUri] = useState<string | null>(null);
  const [attachmentPath, setAttachmentPath] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [form, setForm] = useState<ReimbursementCreateInput>({
    vendor: '',
    total_amount: 0,
    receipt_date: todayIso(),
    category: 'transport',
    description: '',
  });

  const myQ = useQuery({ queryKey: ['reimb.my'], queryFn: ReimbursementApi.my });

  const submitMut = useMutation({
    mutationFn: (input: ReimbursementCreateInput) => ReimbursementApi.create(input),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Pengajuan reimburse dikirim');
      resetForm();
      queryClient.invalidateQueries({ queryKey: ['reimb.my'] });
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mengajukan';
      Alert.alert('Error', msg);
    },
  });

  const resetForm = () => {
    setShowForm(false);
    setReceiptUri(null);
    setAttachmentPath(null);
    setForm({ vendor: '', total_amount: 0, receipt_date: todayIso(), category: 'transport', description: '' });
  };

  const captureReceipt = async (source: 'camera' | 'library') => {
    const result = source === 'camera'
      ? await launchCamera({ mediaType: 'photo', quality: 0.7, saveToPhotos: false })
      : await launchImageLibrary({ mediaType: 'photo', quality: 0.7 });
    if (result.didCancel) return;
    const asset = result.assets?.[0];
    if (!asset?.uri) {
      Alert.alert('Error', 'Tidak bisa membaca foto');
      return;
    }
    setReceiptUri(asset.uri);

    // Upload async
    setUploading(true);
    try {
      const upload = await ReimbursementApi.uploadReceipt(asset.uri);
      setAttachmentPath(upload.attachment_path);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Upload gagal';
      Alert.alert('Upload error', msg);
    } finally {
      setUploading(false);
    }
  };

  const handleSubmit = () => {
    if (!form.vendor.trim() || form.total_amount <= 0 || !form.receipt_date) {
      Alert.alert('Validasi', 'Vendor, total, dan tanggal wajib diisi');
      return;
    }
    submitMut.mutate({
      ...form,
      vendor: form.vendor.trim(),
      attachment_path: attachmentPath ?? undefined,
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
          <Text style={[styles.h1, { color: c.text }]}>Reimburse</Text>
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
            {/* Receipt block */}
            {receiptUri ? (
              <View>
                <Image source={{ uri: receiptUri }} style={styles.preview} resizeMode="cover" />
                {uploading && <Text style={[styles.muted, { color: c.warning }]}>Mengupload struk...</Text>}
                {attachmentPath && (
                  <Text style={[styles.muted, { color: c.success }]}>✓ Struk terupload</Text>
                )}
                <TouchableOpacity onPress={() => { setReceiptUri(null); setAttachmentPath(null); }} style={styles.linkBtn}>
                  <Text style={{ color: c.primary, fontSize: 13 }}>Ganti foto</Text>
                </TouchableOpacity>
              </View>
            ) : (
              <View style={styles.captureRow}>
                <TouchableOpacity
                  style={[styles.captureBtn, { backgroundColor: c.primary }]}
                  onPress={() => captureReceipt('camera')}
                >
                  <Text style={[styles.captureText, { color: c.primaryText }]}>📷 Foto Struk</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={[styles.captureBtn, { backgroundColor: c.surfaceAlt, borderColor: c.border, borderWidth: 1 }]}
                  onPress={() => captureReceipt('library')}
                >
                  <Text style={[styles.captureText, { color: c.text }]}>🖼️ Galeri</Text>
                </TouchableOpacity>
              </View>
            )}

            <Text style={[styles.label, { color: c.text }]}>Vendor / Toko</Text>
            <TextInput
              value={form.vendor}
              onChangeText={(v) => setForm({ ...form, vendor: v })}
              placeholder="Cth: Indomaret, GoFood, Pertamina"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <Text style={[styles.label, { color: c.text }]}>Total (Rp)</Text>
            <TextInput
              value={form.total_amount > 0 ? String(form.total_amount) : ''}
              onChangeText={(v) => setForm({ ...form, total_amount: parseInt(v.replace(/\D/g, ''), 10) || 0 })}
              keyboardType="number-pad"
              placeholder="50000"
              placeholderTextColor={c.textMuted}
              style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <Text style={[styles.label, { color: c.text }]}>Tanggal struk</Text>
            <TextInput
              value={form.receipt_date}
              onChangeText={(v) => setForm({ ...form, receipt_date: v })}
              placeholder="YYYY-MM-DD"
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

            <Text style={[styles.label, { color: c.text }]}>Keterangan</Text>
            <TextInput
              value={form.description}
              onChangeText={(v) => setForm({ ...form, description: v })}
              placeholder="Penjelasan keperluan..."
              placeholderTextColor={c.textMuted}
              multiline
              numberOfLines={2}
              style={[styles.input, styles.textarea, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
            />

            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary, opacity: submitMut.isPending ? 0.6 : 1 }]}
              onPress={handleSubmit}
              disabled={submitMut.isPending || uploading}
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
                <Text style={[styles.bName, { color: c.text }]}>{r.vendor}</Text>
                <Text style={[styles.bSub, { color: c.textMuted }]}>
                  {r.receipt_date} • {r.category}
                </Text>
                {r.description ? (
                  <Text style={[styles.bSub, { color: c.textMuted }]} numberOfLines={1}>
                    {r.description}
                  </Text>
                ) : null}
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                <Text style={[styles.amountText, { color: c.text }]}>{fmtRupiah(r.total_amount)}</Text>
                <Text style={[styles.statusPill, { color: sc }]}>{r.status}</Text>
              </View>
            </View>
          );
        })}
        {!myQ.isLoading && (myQ.data ?? []).length === 0 && (
          <Text style={[styles.empty, { color: c.textMuted }]}>Belum ada pengajuan reimburse.</Text>
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
  muted: { fontSize: 12, marginTop: 6 },
  cta: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8 },
  ctaText: { fontWeight: '600', fontSize: 14 },
  formCard: {
    marginTop: 12,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  captureRow: { flexDirection: 'row' },
  captureBtn: {
    flex: 1,
    paddingVertical: 14,
    borderRadius: 8,
    alignItems: 'center',
    marginRight: 8,
  },
  captureText: { fontSize: 14, fontWeight: '600' },
  preview: { width: '100%', height: 180, borderRadius: 8 },
  linkBtn: { paddingVertical: 8, alignItems: 'center' },
  label: { fontSize: 13, fontWeight: '600', marginTop: 12, marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
  textarea: { minHeight: 60, textAlignVertical: 'top' },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
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
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  bName: { fontSize: 15, fontWeight: '500' },
  bSub: { fontSize: 12, marginTop: 2 },
  amountText: { fontSize: 14, fontWeight: '700' },
  statusPill: { fontSize: 11, fontWeight: '700', marginTop: 4 },
  empty: { textAlign: 'center', paddingVertical: 24, fontSize: 13 },
});