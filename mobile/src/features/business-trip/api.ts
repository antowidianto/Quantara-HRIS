import { api } from '@api/client';

export interface BusinessTripRecord {
  id: number;
  destination: string;
  start_date: string;
  end_date: string;
  purpose: string;
  perdiem_amount?: number | null;
  status:
    | 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED'
    | 'IN_PROGRESS' | 'COMPLETED' | 'SETTLED' | 'CANCELLED';
  total_expenses?: number;
  approved_at?: string | null;
}

export interface BusinessTripCreateInput {
  destination: string;
  start_date: string;
  end_date: string;
  purpose: string;
  perdiem_amount?: number;
}

export const BusinessTripApi = {
  my(): Promise<BusinessTripRecord[]> {
    return api.get<BusinessTripRecord[]>('/business-trips?my=1');
  },
  show(id: number): Promise<BusinessTripRecord> {
    return api.get<BusinessTripRecord>(`/business-trips/${id}`);
  },
  create(input: BusinessTripCreateInput): Promise<BusinessTripRecord> {
    return api.post<BusinessTripRecord>('/business-trips', input);
  },
  submit(id: number): Promise<unknown> {
    return api.post(`/business-trips/${id}/submit`);
  },
  start(id: number): Promise<unknown> {
    return api.post(`/business-trips/${id}/start`);
  },
  complete(id: number): Promise<unknown> {
    return api.post(`/business-trips/${id}/complete`);
  },
  cancel(id: number): Promise<unknown> {
    return api.post(`/business-trips/${id}/cancel`);
  },
};