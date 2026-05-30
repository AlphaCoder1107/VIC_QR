import {api} from "./client";
import {GenericDataResponse, GenericPaginatedResponse, IdParam} from "../types";

export interface StudentRosterRecord {
    id: number;
    enrollment_no: string;
    name: string;
    email: string | null;
    phone: string | null;
    has_purchased: boolean;
    razorpay_payment_id: string | null;
    scan_count: number;
    last_scanned_at: string | null;
}

export interface RosterImportResponse {
    imported: number;
    updated: number;
    skipped_purchased: number;
    errors: {
        row: number;
        enrollment_no: string;
        reason: string;
    }[];
}

export interface FreePrefixRecord {
    id: number;
    event_id: number;
    prefix: string;
    label: string | null;
    created_at: string;
    updated_at: string;
}

export interface FreePrefixSettingsResponse {
    prefixes: FreePrefixRecord[];
    ticket_price_paise: number;
}

export const rosterClient = {
    import: async (organizerId: IdParam, eventId: IdParam, file: File): Promise<RosterImportResponse> => {
        const formData = new FormData();
        formData.append('file', file);

        const response = await api.post<RosterImportResponse>(
            `/auth/organiser/${organizerId}/events/${eventId}/roster/import`,
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }
        );
        return response.data;
    },

    all: async (
        organizerId: IdParam,
        eventId: IdParam,
        params: { page?: number; search?: string; filter?: string }
    ): Promise<GenericPaginatedResponse<StudentRosterRecord>> => {
        const queryParams = new URLSearchParams();
        if (params.page) queryParams.append('page', String(params.page));
        if (params.search) queryParams.append('search', params.search);
        if (params.filter) queryParams.append('filter', params.filter);

        const response = await api.get<GenericPaginatedResponse<StudentRosterRecord>>(
            `/auth/organiser/${organizerId}/events/${eventId}/roster?` + queryParams.toString()
        );
        return response.data;
    },

    delete: async (organizerId: IdParam, eventId: IdParam, rosterId: number): Promise<void> => {
        await api.delete(
            `/auth/organiser/${organizerId}/events/${eventId}/roster/${rosterId}`
        );
    },

    downloadTemplate: async (organizerId: IdParam, eventId: IdParam): Promise<Blob> => {
        const response = await api.get(`/auth/organiser/${organizerId}/events/${eventId}/roster/template`, {
            responseType: 'blob',
        });
        return new Blob([response.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    },

    getSettings: async (organizerId: IdParam, eventId: IdParam): Promise<FreePrefixSettingsResponse> => {
        const response = await api.get<FreePrefixSettingsResponse>(
            `/auth/organiser/${organizerId}/events/${eventId}/free-prefixes`
        );
        return response.data;
    },

    addPrefix: async (
        organizerId: IdParam,
        eventId: IdParam,
        payload: { prefix: string; label?: string }
    ): Promise<FreePrefixRecord> => {
        const response = await api.post<FreePrefixRecord>(
            `/auth/organiser/${organizerId}/events/${eventId}/free-prefixes`,
            payload
        );
        return response.data;
    },

    deletePrefix: async (organizerId: IdParam, eventId: IdParam, prefixId: number): Promise<void> => {
        await api.delete(
            `/auth/organiser/${organizerId}/events/${eventId}/free-prefixes/${prefixId}`
        );
    },

    updatePrice: async (organizerId: IdParam, eventId: IdParam, price: number): Promise<{ ticket_price_paise: number }> => {
        const response = await api.patch<{ ticket_price_paise: number }>(
            `/auth/organiser/${organizerId}/events/${eventId}/ticket-price`,
            { price }
        );
        return response.data;
    },

    importVerifications: async (organizerId: IdParam, eventId: IdParam, file: File, source: 'btech' | 'bca'): Promise<VerificationImportResponse> => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('source', source);

        const response = await api.post<VerificationImportResponse>(
            `/auth/organiser/${organizerId}/events/${eventId}/payment-verifications/import`,
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }
        );
        return response.data;
    },

    allVerifications: async (
        organizerId: IdParam,
        eventId: IdParam,
        params: { page?: number; search?: string; status?: string; source?: string }
    ): Promise<GenericPaginatedResponse<PaymentVerificationRecord>> => {
        const queryParams = new URLSearchParams();
        if (params.page) queryParams.append('page', String(params.page));
        if (params.search) queryParams.append('search', params.search);
        if (params.status) queryParams.append('status', params.status);
        if (params.source) queryParams.append('source', params.source);

        const response = await api.get<GenericPaginatedResponse<PaymentVerificationRecord>>(
            `/auth/organiser/${organizerId}/events/${eventId}/payment-verifications?` + queryParams.toString()
        );
        return response.data;
    },

    verifyVerification: async (organizerId: IdParam, eventId: IdParam, id: number): Promise<{ status: string; message: string }> => {
        const response = await api.post<{ status: string; message: string }>(
            `/auth/organiser/${organizerId}/events/${eventId}/payment-verifications/${id}/verify`
        );
        return response.data;
    },

    rejectVerification: async (organizerId: IdParam, eventId: IdParam, id: number, notes?: string): Promise<{ status: string; message: string }> => {
        const response = await api.post<{ status: string; message: string }>(
            `/auth/organiser/${organizerId}/events/${eventId}/payment-verifications/${id}/reject`,
            { notes }
        );
        return response.data;
    },
};

export interface PaymentVerificationRecord {
    id: number;
    source: 'btech' | 'bca';
    name: string;
    enrollment_no: string;
    email: string;
    phone: string | null;
    transaction_id: string | null;
    screenshot_url: string | null;
    payment_method: string | null;
    status: 'PENDING' | 'VERIFIED' | 'REJECTED';
    notes: string | null;
    created_at: string;
    enrollment_valid: string;
    name_match: string;
    already_purchased: boolean;
    duplicate_txn: boolean;
}

export interface VerificationImportResponse {
    imported: number;
    skipped: number;
}
