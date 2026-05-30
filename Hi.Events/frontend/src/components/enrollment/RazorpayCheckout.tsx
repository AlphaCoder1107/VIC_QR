import {useCallback, useEffect, useRef, useState} from "react";
import {Alert, Group, Loader, Stack, Text} from "@mantine/core";
import {publicApi} from "../../api/public-client.ts";
import {Button} from "../common/Button";

declare global {
    interface Window {
        Razorpay?: new (options: RazorpayOptions) => RazorpayInstance;
    }
}

type InitiateOrderResponse = {
    razorpay_order_id: string;
    amount_paise: number;
    key_id: string;
};

type Props = {
    enrollmentNo: string;
    eventId: number;
    studentName: string;
    studentEmail: string;
    onSuccess: () => void;
    onFailure: () => void;
};

type RazorpayOptions = {
    key: string;
    amount: number;
    currency: string;
    name: string;
    description: string;
    order_id: string;
    prefill: {
        name: string;
        email: string;
    };
    theme?: {
        color?: string;
    };
    handler?: (response: {
        razorpay_payment_id: string;
        razorpay_order_id: string;
        razorpay_signature: string;
    }) => void;
    modal?: {
        ondismiss?: () => void;
    };
};

type RazorpayInstance = {
    open: () => void;
};

const RAZORPAY_SCRIPT_ID = 'razorpay-checkout-js';
const RAZORPAY_SCRIPT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

const loadRazorpayScript = async (): Promise<void> => {
    if (typeof window === 'undefined') {
        throw new Error('Razorpay checkout can only be loaded in a browser');
    }

    if (window.Razorpay) {
        return;
    }

    const existingScript = document.getElementById(RAZORPAY_SCRIPT_ID) as HTMLScriptElement | null;
    if (existingScript) {
        await new Promise<void>((resolve, reject) => {
            if (window.Razorpay) {
                resolve();
                return;
            }

            existingScript.addEventListener('load', () => resolve(), {once: true});
            existingScript.addEventListener('error', () => reject(new Error('Failed to load Razorpay checkout script')), {once: true});
        });

        return;
    }

    await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.id = RAZORPAY_SCRIPT_ID;
        script.src = RAZORPAY_SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Razorpay checkout script'));
        document.body.appendChild(script);
    });
};

const initiateOrder = async (eventId: number, enrollmentNo: string): Promise<InitiateOrderResponse> => {
    const response = await publicApi.post<InitiateOrderResponse>(`events/${eventId}/enrollment/initiate-order`, {
        enrollment_no: enrollmentNo,
        event_id: eventId,
    });

    return response.data;
};

export const RazorpayCheckout = ({
    enrollmentNo,
    eventId,
    studentName,
    studentEmail,
    onSuccess,
    onFailure,
}: Props) => {
    const [isLoading, setIsLoading] = useState(true);
    const [isOpening, setIsOpening] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const mountedRef = useRef(true);

    const openCheckout = useCallback(async (orderData: InitiateOrderResponse) => {
        await loadRazorpayScript();

        if (!mountedRef.current || typeof window === 'undefined' || !window.Razorpay) {
            throw new Error('Razorpay checkout is unavailable');
        }

        const checkout = new window.Razorpay({
            key: orderData.key_id,
            order_id: orderData.razorpay_order_id,
            amount: orderData.amount_paise,
            currency: 'INR',
            name: 'VIC Farewell',
            description: `Ticket for ${studentName}`,
            prefill: {
                name: studentName,
                email: studentEmail,
            },
            theme: {
                color: '#1f2937',
            },
            handler: async (response: {
                razorpay_payment_id: string;
                razorpay_order_id: string;
                razorpay_signature: string;
            }) => {
                if (!mountedRef.current) {
                    return;
                }

                setIsOpening(false);
                setSuccessMessage('Payment received! Your ticket is on its way.');
                onSuccess();

                try {
                    await publicApi.post(`events/${eventId}/enrollment/verify-payment`, {
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                        enrollment_no: enrollmentNo,
                        event_id: eventId,
                    });
                } catch (err) {
                    console.error('Payment verification call failed:', err);
                }
            },
            modal: {
                ondismiss: () => {
                    if (!mountedRef.current) {
                        return;
                    }

                    setIsOpening(false);
                    setError('Payment was dismissed. You can try again.');
                    onFailure();
                },
            },
        });

        checkout.open();
    }, [onFailure, onSuccess, studentEmail, studentName]);

    useEffect(() => {
        mountedRef.current = true;

        const run = async () => {
            try {
                setIsLoading(true);
                setError(null);
                const orderData = await initiateOrder(eventId, enrollmentNo);
                if (!mountedRef.current) {
                    return;
                }

                setIsOpening(true);
                await openCheckout(orderData);
            } catch (e) {
                if (!mountedRef.current) {
                    return;
                }

                const message = e instanceof Error ? e.message : 'Unable to start Razorpay checkout';
                setError(message);
                onFailure();
            } finally {
                if (mountedRef.current) {
                    setIsLoading(false);
                }
            }
        };

        void run();

        return () => {
            mountedRef.current = false;
        };
    }, [enrollmentNo, eventId, onFailure, openCheckout]);

    const retry = () => {
        setError(null);
        setIsLoading(true);
        setIsOpening(false);

        void (async () => {
            try {
                const orderData = await initiateOrder(eventId, enrollmentNo);
                if (!mountedRef.current) {
                    return;
                }

                setIsOpening(true);
                await openCheckout(orderData);
            } catch (retryError) {
                if (!mountedRef.current) {
                    return;
                }

                const message = retryError instanceof Error ? retryError.message : 'Unable to start Razorpay checkout';
                setError(message);
                onFailure();
            } finally {
                if (mountedRef.current) {
                    setIsLoading(false);
                }
            }
        })();
    };

    return (
        <Stack gap="md" align="center" justify="center" style={{minHeight: 200}}>
            {isLoading && (
                <Group gap="sm">
                    <Loader size="sm" type="dots" />
                    <Text size="sm">Preparing secure payment…</Text>
                </Group>
            )}

            {!isLoading && isOpening && (
                <Group gap="sm">
                    <Loader size="sm" />
                    <Text size="sm">Opening Razorpay Checkout…</Text>
                </Group>
            )}

            {successMessage && (
                <Alert color="green" variant="light" w="100%">
                    {successMessage}
                </Alert>
            )}

            {error && (
                <Alert color="red" variant="light" w="100%">
                    {error}
                </Alert>
            )}

            {!isLoading && !successMessage && error && (
                <Button onClick={retry}>
                    Retry Payment
                </Button>
            )}
        </Stack>
    );
};

export default RazorpayCheckout;
