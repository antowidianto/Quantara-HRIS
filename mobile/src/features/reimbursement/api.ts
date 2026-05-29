import { api, apiClient } from '@api/client';

export interface ReimbursementRecord {
  id: number;
  vendor: string;
  total_amount: number;
  receipt_date: string;
  category: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'PAID' | 'CANCELLED';
  description?: string;
  attachment_path?: string | null;
  created_at: string;
}

export interface ReimbursementCreateInput {
  vendor: string;
  total_amount: number;
  receipt_date: string;
  category: string;
  description?: string;
  attachment_path?: string;
}

export interface OcrExtractResult {
  vendor?: string;
  total_amount?: number;
  receipt_date?: string;
  confidence?: number;
}

export const ReimbursementApi = {
  async my(): Promise<ReimbursementRecord[]> {
    return api.get<ReimbursementRecord[]>('/reimbursements?my=1');
  },

  async create(input: ReimbursementCreateInput): Promise<ReimbursementRecord> {
    return api.post<ReimbursementRecord>('/reimbursements', input);
  },

  /**
   * Upload struk dulu (multipart) → backend balas attachment_path.
   * Pakai FileUploadController generic dengan context=reimbursement.
   * Setelah dapat path, panggil `create()` dengan attachment_path.
   */
  async uploadReceipt(uri: string): Promise<{ attachment_path: string }> {
    const fd = new FormData();
    fd.append('context', 'reimbursement');
    fd.append('file', {
      uri,
      type: 'image/jpeg',
      name: 'receipt.jpg',
    } as unknown as Blob);
    const r = await apiClient.post('/upload/attachment?context=reimbursement', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return (r.data?.data ?? r.data) as { attachment_path: string };
  },

  /**
   * OCR extract setelah upload — backend Horizon2Controller::ocrExtractReimbursement
   * pakai OcrService driver (mock di dev, gcloud_vision/aws_textract di prod).
   */
  async ocrExtract(reimbursementId: number): Promise<OcrExtractResult> {
    return api.post<OcrExtractResult>(`/reimbursements/${reimbursementId}/ocr-extract`);
  },
};