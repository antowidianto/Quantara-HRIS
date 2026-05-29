import React, { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
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
import { LeaveApi, type LeaveCreateInput, type LeaveType } from './api';
import { ApiError } from '@api/client';

interface Props {
  onClose: () => void;
}

const todayIso = (): string => new Date().toISOString().slice(0, 10);

/**
 * Form ajukan cuti — Tier 1.3.
 * MVP: pakai TextInput date (format YYYY-MM-DD) untuk simplicity. Production
 * pakai react-native-date-picker (deferred ke polish sprint).
 */
export const LeaveCreateScreen: React.FC<Props> = ({ onClose }) => {
  const theme = useTheme();
  const queryClient = useQueryClient();
  const typesQ = useQuery({ queryKey: ['leave.types'], queryFn: LeaveApi.types });

  const [leaveTypeId, setLeaveTypeId] = useState<number | null>(null);
  const [startDate, setStartDate] = useState<string>(todayIso());
  const [endDate, setEndDate] = useState<string>(todayIso());
  const [reason, setReason] = useState('');
  const [isHalfDay, setIsHalfDay] = useState(false);
  const [halfDayPart, setHalfDayPart] = useState<'MORNING' | 'AFTERNOON'>('MORNING');

  const submitMut = useMutation({
    mutationFn: (input: LeaveCreateInput) => LeaveApi.create(input),
    onSuccess: () => {
      Alert.alert('Berhasil', 'Pengajuan cuti dikirim.');
      queryClient.invalidateQueries({ queryKey: ['leave.my'] });
      queryClient.invalidateQueries({ queryKey: ['leave.balance'] });
      onClose();
    },
    onError: (e: unknown) => {
      const msg = e instanceof ApiError ? e.message : 'Gagal mengajukan cuti';
      Alert.alert('Error', msg);
    },
  });

  const handleSubmit = () => {
    if (!leaveTypeId) {
      Alert.alert('Validasi', 'Pilih jenis cuti dulu');
      return;
    }
    if (!startDate || !endDate) {
      Alert.alert('Validasi', 'Tanggal wajib diisi');
      return;
    }
    if (startDate > endDate) {
      Alert.alert('Validasi', 'Tanggal mulai harus ≤ tanggal akhir');
      return;
    }
    if (reason.trim().length < 5) {
      Alert.alert('Validasi', 'Alasan minimal 5 karakter');
      return;
    }
    const payload: LeaveCreateInput = {
      leave_type_id: leaveTypeId,
      start_date: startDate,
      end_date: endDate,
      reason: reason.trim(),
      is_half_day: isHalfDay,
    };
    if (isHalfDay) payload.half_day_part = halfDayPart;
    submitMut.mutate(payload);
  };

  const c = theme.colors;
  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Text style={[styles.h1, { color: c.text }]}>Ajukan Cuti</Text>

        <Text style={[styles.label, { color: c.text }]}>Jenis cuti</Text>
        <View style={styles.typesRow}>
          {(typesQ.data ?? []).map((t: LeaveType) => {
            const sel = leaveTypeId === t.id;
            return (
              <TouchableOpacity
                key={t.id}
                onPress={() => setLeaveTypeId(t.id)}
                style={[
                  styles.typeChip,
                  {
                    backgroundColor: sel ? c.primary : c.surface,
                    borderColor: sel ? c.primary : c.border,
                  },
                ]}
              >
                <Text style={{ color: sel ? c.primaryText : c.text, fontSize: 13, fontWeight: '500' }}>
                  {t.name}
                </Text>
              </TouchableOpacity>
            );
          })}
        </View>

        <Text style={[styles.label, { color: c.text }]}>Tanggal mulai</Text>
        <TextInput
          value={startDate}
          onChangeText={setStartDate}
          placeholder="YYYY-MM-DD"
          placeholderTextColor={c.textMuted}
          autoCapitalize="none"
          keyboardType="numbers-and-punctuation"
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <Text style={[styles.label, { color: c.text }]}>Tanggal akhir</Text>
        <TextInput
          value={endDate}
          onChangeText={setEndDate}
          placeholder="YYYY-MM-DD"
          placeholderTextColor={c.textMuted}
          autoCapitalize="none"
          keyboardType="numbers-and-punctuation"
          style={[styles.input, { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt }]}
        />

        <View style={styles.toggleRow}>
          <Text style={[styles.label, { color: c.text, marginBottom: 0 }]}>Cuti setengah hari?</Text>
          <TouchableOpacity onPress={() => setIsHalfDay((v) => !v)}>
            <Text style={{ color: c.primary, fontWeight: '600' }}>{isHalfDay ? 'YA' : 'TIDAK'}</Text>
          </TouchableOpacity>
        </View>
        {isHalfDay && (
          <View style={styles.typesRow}>
            {(['MORNING', 'AFTERNOON'] as const).map((p) => {
              const sel = halfDayPart === p;
              return (
                <TouchableOpacity
                  key={p}
                  onPress={() => setHalfDayPart(p)}
                  style={[
                    styles.typeChip,
                    { backgroundColor: sel ? c.primary : c.surface, borderColor: sel ? c.primary : c.border },
                  ]}
                >
                  <Text style={{ color: sel ? c.primaryText : c.text, fontSize: 13 }}>
                    {p === 'MORNING' ? 'Pagi' : 'Siang'}
                  </Text>
                </TouchableOpacity>
              );
            })}
          </View>
        )}

        <Text style={[styles.label, { color: c.text }]}>Alasan</Text>
        <TextInput
          value={reason}
          onChangeText={setReason}
          placeholder="Jelaskan keperluan cuti..."
          placeholderTextColor={c.textMuted}
          multiline
          numberOfLines={4}
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
        <TouchableOpacity onPress={onClose} style={styles.cancelBtn}>
          <Text style={{ color: c.textMuted, fontSize: 14 }}>Batal</Text>
        </TouchableOpacity>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 20, paddingBottom: 40 },
  h1: { fontSize: 22, fontWeight: '700', marginBottom: 20 },
  label: { fontSize: 13, fontWeight: '600', marginTop: 16, marginBottom: 6 },
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 12,
    fontSize: 15,
  },
  textarea: { minHeight: 90, textAlignVertical: 'top' },
  typesRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  typeChip: {
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
    marginRight: 8,
    marginBottom: 8,
  },
  toggleRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 16,
  },
  button: {
    marginTop: 24,
    borderRadius: 8,
    paddingVertical: 14,
    alignItems: 'center',
  },
  buttonText: { fontSize: 16, fontWeight: '600' },
  cancelBtn: { alignItems: 'center', paddingVertical: 12, marginTop: 4 },
});