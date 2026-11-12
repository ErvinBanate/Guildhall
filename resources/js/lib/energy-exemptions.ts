export const exemptionRoles = {
    mentor: 'Mentor',
    officer: 'Officer',
    guild_master: 'Guild master',
    none: 'No exemption',
};

export type EnergyExemption = {
    role: keyof typeof exemptionRoles;
    active: boolean;
    revision: number;
    note: string;
};
