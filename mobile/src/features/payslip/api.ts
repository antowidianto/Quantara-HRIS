import { api } from '@api/client';
import { env } from '@/config';
import { SecureStorage } from '@store/secureStorage';

export interface PayslipSummary {
  id: number;
  period_label: string;
  period_year: number;
  period_month: number;
  gross_amount: number;
  net_amount: number;
  published_at: string;
}

export interface PayslipLine {
  code: string;
  name: string;
  amount: number;
}

export interface PayslipDetail extends PayslipSummary {
  earnings: PayslipLine[];
  deductions: PayslipLine[];
}

export const PayslipApi = {
  async my(): Promise<PayslipSummary[]> {
    return api.get<PayslipSummary[]>('/payslips/my');
  },
  async show(id: number): Promise<PayslipDetail> {
    return api.get<PayslipDetail>(`/payslips/${id}`);
  },
  /**
   * URL untuk download PDF dengan auth header. Mobile pakai fetch+blob
   * (atau react-native-blob-util di production) supaya bisa pasang Bearer.
   */
  async pdfUrl(id: number): Promise<{ url: string; bearer: string }> {
    const tokens = await SecureStorage.loadAuth();
    return {
      url: `${env.apiBaseUrl}/payslips/${id}/pdf`,
      bearer: tokens?.accessToken ?? '',
    };
  },
};