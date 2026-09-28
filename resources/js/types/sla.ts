export type SlaCycle = {
    id: number;
    cycle_number: number;
    timezone: string;
    started_at: string;
    acknowledged_at: string | null;
    held_at: string | null;
    closed_at: string | null;
    outcome: string | null;
    response_seconds: number;
    resolution_seconds: number;
    response_elapsed_seconds: number;
    resolution_elapsed_seconds: number;
    response_breached: boolean;
    resolution_breached: boolean;
    holds: { start: string; end: string; reason: string }[];
};
