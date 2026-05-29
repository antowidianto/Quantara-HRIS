import React, { useState } from 'react';
import {
  ActivityIndicator,
  Linking,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useQuery } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { PayslipApi, type PayslipDetail } from './api';

const fmtRupiah = (n: number): string => 'Rp ' + n.toLocaleString('id-ID');
const fmtPeriod = (year: number, month: number): string =>
  new Date(year, month - 1, 1).toLocaleString('id-ID', { month: 'long', year: 'numeric' });

/**
 * Payslip — Tier 1.5.
 * List per bulan + tap untuk lihat breakdown earning/deduction.
 * PDF download deferred ke polish (RN tidak ada built-in download manager;
 * pakai react-native-blob-util di Sprint M3 berikutnya).
 */
export const PayslipScreen: React.FC = () => {
  const theme = useTheme();
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const listQ = useQuery({
    queryKey: ['payslip.my'],
    queryFn: PayslipApi.my,
    staleTime: 5 * 60_000,
  });

  const detailQ = useQuery({
    queryKey: ['payslip.detail', selectedId],
    queryFn: () => (selectedId ? PayslipApi.show(selectedId) : Promise.reject('no id')),
    enabled: selectedId !== null,
  });

  const c = theme.colors;

  if (selectedId !== null) {
    return (
      <PayslipDetailView
        loading={detailQ.isLoading}
        data={detailQ.data}
        onBack={() => setSelectedId(null)}
      />
    );
  }

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={listQ.isRefetching} onRefresh={listQ.refetch} />}
      >
        <Text style={[styles.h1, { color: c.text }]}>Slip Gaji</Text>
        {listQ.isLoading && <ActivityIndicator color={c.primary} style={{ marginTop: 24 }} />}
        {(listQ.data ?? []).map((p) => (
          <TouchableOpacity
            key={p.id}
            onPress={() => setSelectedId(p.id)}
            style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}
          >
            <View style={{ flex: 1 }}>
              <Text style={[styles.period, { color: c.text }]}>{fmtPeriod(p.period_year, p.period_month)}</Text>
              <Text style={[styles.muted, { color: c.textMuted }]}>
                Gross {fmtRupiah(p.gross_amount)}
              </Text>
            </View>
            <View style={{ alignItems: 'flex-end' }}>
              <Text style={[styles.netLabel, { color: c.textMuted }]}>Take-home</Text>
              <Text style={[styles.netAmount, { color: c.success }]}>{fmtRupiah(p.net_amount)}</Text>
            </View>
          </TouchableOpacity>
        ))}
        {!listQ.isLoading && (listQ.data ?? []).length === 0 && (
          <Text style={[styles.muted, { color: c.textMuted, marginTop: 24, textAlign: 'center' }]}>
            Belum ada slip gaji yang di-publish.
          </Text>
        )}
      </ScrollView>
    </SafeAreaView>
  );
};

const PayslipDetailView: React.FC<{
  loading: boolean;
  data: PayslipDetail | undefined;
  onBack: () => void;
}> = ({ loading, data, onBack }) => {
  const theme = useTheme();
  const c = theme.colors;

  const sumLines = (lines: { amount: number }[]): number =>
    lines.reduce((s, l) => s + l.amount, 0);

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView contentContainerStyle={styles.content}>
        <TouchableOpacity onPress={onBack} style={styles.backRow}>
          <Text style={{ color: c.primary, fontWeight: '600' }}>← Kembali</Text>
        </TouchableOpacity>
        {loading || !data ? (
          <ActivityIndicator color={c.primary} style={{ marginTop: 40 }} />
        ) : (
          <>
            <Text style={[styles.h1, { color: c.text }]}>
              {fmtPeriod(data.period_year, data.period_month)}
            </Text>

            <Text style={[styles.section, { color: c.text }]}>Penerimaan</Text>
            {data.earnings.map((l) => (
              <View key={l.code} style={[styles.lineRow, { borderColor: c.border }]}>
                <Text style={[styles.lineName, { color: c.text }]}>{l.name}</Text>
                <Text style={[styles.lineAmt, { color: c.text }]}>{fmtRupiah(l.amount)}</Text>
              </View>
            ))}
            <View style={[styles.lineRow, styles.subtotal, { borderColor: c.border }]}>
              <Text style={[styles.lineName, { color: c.success, fontWeight: '600' }]}>Total Penerimaan</Text>
              <Text style={[styles.lineAmt, { color: c.success, fontWeight: '700' }]}>
                {fmtRupiah(sumLines(data.earnings))}
              </Text>
            </View>

            <Text style={[styles.section, { color: c.text }]}>Potongan</Text>
            {data.deductions.map((l) => (
              <View key={l.code} style={[styles.lineRow, { borderColor: c.border }]}>
                <Text style={[styles.lineName, { color: c.text }]}>{l.name}</Text>
                <Text style={[styles.lineAmt, { color: c.danger }]}>−{fmtRupiah(l.amount)}</Text>
              </View>
            ))}
            <View style={[styles.lineRow, styles.subtotal, { borderColor: c.border }]}>
              <Text style={[styles.lineName, { color: c.danger, fontWeight: '600' }]}>Total Potongan</Text>
              <Text style={[styles.lineAmt, { color: c.danger, fontWeight: '700' }]}>
                −{fmtRupiah(sumLines(data.deductions))}
              </Text>
            </View>

            <View style={[styles.takeHome, { backgroundColor: c.success + '15', borderColor: c.success }]}>
              <Text style={[styles.lineName, { color: c.text, fontWeight: '600' }]}>Take-home</Text>
              <Text style={[styles.takeHomeAmt, { color: c.success }]}>{fmtRupiah(data.net_amount)}</Text>
            </View>

            <TouchableOpacity
              style={[styles.button, { backgroundColor: c.primary }]}
              onPress={async () => {
                const { url, bearer } = await PayslipApi.pdfUrl(data.id);
                // MVP: copy URL ke clipboard atau buka browser dengan token in-band.
                // Production: download via react-native-blob-util ke FileSystem.
                await Linking.openURL(url + (bearer ? `?token=${encodeURIComponent(bearer)}` : ''));
              }}
            >
              <Text style={[styles.buttonText, { color: c.primaryText }]}>Buka PDF</Text>
            </TouchableOpacity>
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  h1: { fontSize: 24, fontWeight: '700' },
  muted: { fontSize: 13 },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 12,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
  },
  period: { fontSize: 16, fontWeight: '600' },
  netLabel: { fontSize: 11, marginBottom: 2 },
  netAmount: { fontSize: 16, fontWeight: '700' },
  backRow: { paddingVertical: 8 },
  section: { fontSize: 14, fontWeight: '700', marginTop: 24, marginBottom: 8 },
  lineRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  subtotal: { paddingVertical: 12 },
  lineName: { fontSize: 14, flex: 1 },
  lineAmt: { fontSize: 14 },
  takeHome: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 24,
    padding: 16,
    borderWidth: 1,
    borderRadius: 12,
  },
  takeHomeAmt: { fontSize: 22, fontWeight: '700' },
  button: { marginTop: 24, borderRadius: 8, paddingVertical: 14, alignItems: 'center' },
  buttonText: { fontSize: 16, fontWeight: '600' },
});