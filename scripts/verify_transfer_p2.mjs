import { verifyRetiredDecisionContracts } from './lib/retired_decision_contract.mjs';

// P2 generation is retired. Verify current scoped reads and side-effect-free rejection.
verifyRetiredDecisionContracts('transfer');
