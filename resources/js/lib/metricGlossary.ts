export interface MetricGlossaryDetail {
    label?: string;
    text: string;
}

export interface MetricGlossaryEntry {
    title: string;
    summary: string;
    formula?: string;
    details?: ReadonlyArray<MetricGlossaryDetail | string>;
    note?: string;
}

export const METRIC_GLOSSARY = {
    decoupling: {
        title: 'Aerobic Decoupling',
        summary:
            'Measures cardiac drift by comparing your Efficiency Factor (output per heartbeat) in the 1st half versus the 2nd half of active effort. Low drift means your aerobic system stayed steady without your heart rate climbing to hold the same output.',
        formula: '((EF₁ − EF₂) ÷ EF₁) × 100%',
        details: [
            {
                label: '< 5% (Well Coupled)',
                text: 'Strong aerobic durability for this duration and effort.',
            },
            {
                label: '5–10% (Moderate Drift)',
                text: 'Noticeable fatigue, heat, or dehydration building in the 2nd half.',
            },
            {
                label: '> 10% (High Drift)',
                text: 'Significant aerobic drift or pacing fade over the session.',
            },
            {
                label: 'Output basis',
                text: 'Uses Pw:Hr (Normalized Power) when ≥80% power coverage exists, Pa:Hr (Grade-Adjusted Pace) when ≥80% GAP exists, otherwise Spd:Hr (raw speed).',
            },
        ],
        note: 'Requires at least 20 minutes of active moving time (pauses excluded). Best interpreted on steady-state endurance sessions, since intervals, big climbs, or heat naturally raise drift.',
    },
    efficiency_factor: {
        title: 'Efficiency Factor (EF)',
        summary:
            'Measures how much output you produce for each heartbeat. Tracking EF on similar steady aerobic sessions over weeks and months shows whether your aerobic fitness is improving.',
        formula: 'EF = Output ÷ Avg HR  (W/bpm or pace/speed per bpm)',
        details: [
            {
                label: 'Power sessions',
                text: 'Normalized Power (W) divided by time-weighted average heart rate (W/bpm).',
            },
            {
                label: 'Pace / speed sessions',
                text: 'Time-weighted average Grade-Adjusted Pace (or raw speed) divided by average heart rate.',
            },
            {
                label: 'Trend direction',
                text: 'Higher EF at the same aerobic intensity means more speed or power for the same cardiovascular cost.',
            },
        ],
        note: 'Only compare EF across sessions that use the same output basis (power vs pace/speed) and similar steady-state intensity.',
    },
    pacing_strategy: {
        title: 'Pacing Strategy & Split Ratio',
        summary:
            'Compares your average speed in the 2nd half of full splits against the 1st half to show whether you finished faster, even, or faded.',
        formula: 'Split Ratio = 2nd-Half Speed ÷ 1st-Half Speed',
        details: [
            {
                label: '≥ 1.015× (Negative Split)',
                text: 'Faster 2nd half, reflecting a controlled start with a strong finish.',
            },
            {
                label: '0.985–1.015× (Even Split)',
                text: 'Balanced speed from start to finish.',
            },
            {
                label: '≤ 0.985× (Positive Split)',
                text: 'Faster 1st half with a slower 2nd half.',
            },
        ],
        note: 'Excludes a final partial split shorter than 400 m so short kick-ins do not skew the ratio.',
    },
    pace_cv: {
        title: 'Split Pace CV (%)',
        summary:
            'Coefficient of Variation of your full split paces, measuring how tightly your split-to-split pacing clustered around your average pace.',
        formula: 'CV = (Std Dev of Split Paces ÷ Mean Split Pace) × 100%',
        details: [
            {
                label: 'Lower %',
                text: 'Steadier, more metronomic pacing across splits.',
            },
            {
                label: 'Higher %',
                text: 'More variable splits caused by intervals, hills, stops, or pacing surges.',
            },
        ],
        note: 'Computed from full splits only (excluding any final partial split under 400 m).',
    },
    hr_intensity_split: {
        title: 'HR Intensity Split',
        summary:
            'Groups your 5 heart-rate zones into 3 training intensity bands so you can see at a glance how easy, moderate, or hard a session was.',
        details: [
            {
                label: 'Easy (Z1–Z2)',
                text: 'Low aerobic base and recovery intensity.',
            },
            {
                label: 'Moderate (Z3)',
                text: 'Tempo / aerobic "grey zone" effort.',
            },
            {
                label: 'Hard (Z4–Z5)',
                text: 'Threshold, VO2 max, and peak anaerobic effort.',
            },
        ],
    },
    hr_polarization: {
        title: 'HR Zone Distribution & Polarization',
        summary:
            'Breaks total heart-rate zone time into Easy (Z1–Z2), Moderate (Z3), and Hard (Z4–Z5) to show how polarized your training mix is.',
        details: [
            {
                label: '80/20 Polarized Target',
                text: 'Classic endurance model targets ~80% Easy (Z1–Z2), minimal Moderate (Z3), and ~15–20% Hard (Z4–Z5).',
            },
            {
                label: 'Why minimize Z3?',
                text: 'Too much moderate "grey zone" work adds fatigue without maximizing aerobic base or high-end stimulus.',
            },
        ],
    },
    trimp: {
        title: 'HR Effort (TRIMP)',
        summary:
            "Vendor-neutral heart-rate Training Impulse inspired by Dr. Eric Banister's model. Quantifies cardiovascular stress from duration and heart-rate intensity across any HR monitor.",
        formula:
            'Σ (Zone Minutes × Weight)  [Z1: 0.5, Z2: 1.2, Z3: 2.4, Z4: 4.5, Z5: 7.5]',
        details: [
            {
                label: 'Zone-weighted score',
                text: 'Higher heart-rate zones carry exponentially greater weight per minute.',
            },
            {
                label: 'Avg-HR fallback',
                text: 'When HR-zone durations are missing, falls back to Banister exponential TRIMP from average HR, resting HR, and max HR.',
            },
            {
                label: 'vs Garmin Exercise Load',
                text: 'Garmin Load uses proprietary EPOC estimates; TRIMP stays consistent across devices and sports.',
            },
        ],
    },
    mmp_curve: {
        title: 'Mean Maximal Power Curve',
        summary:
            'Plots the highest average power (in Watts or W/kg) you sustained continuously across durations from short sprints (1s–30s) up to long endurance efforts (20m–2h).',
        details: [
            {
                label: 'Short end (1s–30s)',
                text: 'Neuromuscular sprint and peak jump power.',
            },
            {
                label: 'Middle (1m–5m)',
                text: 'Anaerobic capacity and VO2 max power.',
            },
            {
                label: 'Long end (20m–2h)',
                text: 'Lactate threshold (FTP) and aerobic stamina.',
            },
        ],
        note: 'Comparing 90-day, season, and all-time curves highlights where your current form sits relative to historical peaks.',
    },
    power_zones: {
        title: 'Power Zones',
        summary:
            'Time spent across power intensity bands anchored to your Functional Threshold Power (FTP).',
        details: [
            {
                label: 'Cycling (Coggan 7-Zone)',
                text: 'Z1 Active Recovery, Z2 Endurance, Z3 Tempo, Z4 Lactate Threshold, Z5 VO2 Max, Z6 Anaerobic Capacity, Z7 Neuromuscular.',
            },
            {
                label: 'Running (5-Zone)',
                text: 'Easy, Moderate, Threshold, High Intensity, and Maximum running power bands.',
            },
        ],
        note: 'Power responds instantaneously to effort changes without heart-rate lag, making power zones ideal for short intervals and climbs.',
    },
    gap: {
        title: 'GAP (Grade-Adjusted Pace)',
        summary:
            'Estimates the equivalent flat-ground pace that matches the same metabolic effort after accounting for uphill and downhill gradient.',
        details: [
            {
                label: 'Uphill',
                text: 'GAP is faster than raw pace because climbing demands more energy per kilometer.',
            },
            {
                label: 'Gentle downhill',
                text: 'GAP is slightly slower than raw pace because gravity assists forward speed.',
            },
            {
                label: 'Steep downhill',
                text: 'Braking forces raise metabolic cost again, so GAP stops getting slower on steep descents.',
            },
        ],
        note: 'Useful for comparing pacing consistency and aerobic fitness across hilly and flat routes.',
    },
    normalized_power: {
        title: 'Normalized Power (NP)',
        summary:
            'Estimates the steady-state power that would have caused the same physiological cost as a variable-effort ride or run.',
        formula: '4th root of the mean of (30-second rolling avg power)⁴',
        details: [
            {
                label: 'Why it differs from Avg Power',
                text: 'Because surges and climbs cost disproportionately more energy than coasting saves, NP is equal to or higher than average power.',
            },
            {
                label: 'Variability check',
                text: 'An NP close to Avg Power signals steady pacing; a wide gap signals surging, hills, or group attacks.',
            },
        ],
    },
    intensity_factor: {
        title: 'Intensity Factor (IF)',
        summary:
            'Shows how hard a session was relative to your Functional Threshold Power (FTP).',
        formula: 'IF = Normalized Power ÷ FTP',
        details: [
            { label: '< 0.75', text: 'Recovery or steady endurance.' },
            { label: '0.75–0.85', text: 'Tempo / aerobic endurance.' },
            { label: '0.85–0.95', text: 'Sub-threshold / sweet spot.' },
            { label: '0.95–1.05', text: 'Threshold effort or ~1-hour race.' },
            { label: '> 1.05', text: 'Short race, crit, or VO2 intervals.' },
        ],
    },
    tss: {
        title: 'Training Stress Score (TSS)',
        summary:
            'Quantifies total workout load by combining duration and power intensity relative to your FTP.',
        formula: 'TSS = (Duration_s × NP × IF) ÷ (FTP × 3600) × 100',
        details: [
            {
                label: 'Reference anchor',
                text: '1 hour ridden all-out at FTP (IF = 1.00) equals exactly 100 TSS.',
            },
            {
                label: 'Rough scale',
                text: '< 150 low/moderate (next-day recovery), 150–300 high, 300+ very high multi-day fatigue.',
            },
        ],
    },
    grit_flow: {
        title: 'Garmin Grit & Flow',
        summary:
            'Off-road and MTB trail telemetry measuring route difficulty (Grit) and riding smoothness (Flow).',
        details: [
            {
                label: 'Grit (higher = harder)',
                text: 'Rates trail difficulty from elevation changes, steepness, and sharp cornering angles.',
            },
            {
                label: 'Flow (lower = smoother)',
                text: 'Measures how consistently you carried speed through turns and terrain. Lower Flow scores indicate smoother, better-controlled riding.',
            },
        ],
        note: 'Remember the direction: higher Grit means a tougher trail, while lower Flow means smoother execution.',
    },
    wind_impact: {
        title: 'Wind Impact & Net Wind',
        summary:
            'Projects Open-Meteo wind vectors sampled along your route waypoints onto your GPS travel heading to show how much the wind helped or fought you.',
        details: [
            {
                label: 'Headwind / Tailwind / Crosswind %',
                text: 'Share of route movement facing within 45° of the wind (Headwind), within 45° with the wind (Tailwind), or 45°–135° across it (Crosswind). Calm air is below 3 km/h.',
            },
            {
                label: 'Net Wind',
                text: 'Average wind component along your travel direction: positive (+) = net tailwind assist; negative (−) = net headwind resistance.',
            },
        ],
    },
    ghost_pacer: {
        title: 'Ghost Pacer',
        summary:
            'Aligns 2–3 activities against your chosen Baseline session to visualize where you pulled ahead or fell behind along the course.',
        details: [
            {
                label: 'Distance alignment (Time Gap)',
                text: 'Shows elapsed time difference at each distance point. Negative (−) means faster / ahead of Baseline; positive (+) means slower / behind.',
            },
            {
                label: 'Time alignment (Distance Gap)',
                text: 'Shows distance covered difference at each timestamp. Positive (+) means farther ahead of Baseline; negative (−) means behind.',
            },
        ],
    },
    ctl: {
        title: 'Fitness (CTL)',
        summary:
            'Chronic Training Load is a 42-day exponentially weighted moving average (EWMA) of your daily training load representing long-term aerobic fitness.',
        formula: 'CTL_today = CTL_yesterday + (Load − CTL_yesterday) ÷ 42',
        details: [
            {
                label: 'Builds slowly',
                text: 'Reflects ~6 weeks of consistent training; single workouts move CTL gradually.',
            },
            {
                label: 'Consistent warmup',
                text: 'Seeded over 252 prior days (6× time constant) so CTL values do not shift when you change the chart date window.',
            },
        ],
    },
    atl: {
        title: 'Fatigue (ATL)',
        summary:
            'Acute Training Load is a 7-day exponentially weighted moving average (EWMA) of daily training load capturing short-term fatigue.',
        formula: 'ATL_today = ATL_yesterday + (Load − ATL_yesterday) ÷ 7',
        details: [
            {
                label: 'Reacts rapidly',
                text: 'Spikes quickly after hard training blocks and drops fast during rest or taper weeks.',
            },
        ],
    },
    tsb: {
        title: 'Form (TSB)',
        summary:
            'Training Stress Balance entering today, showing the balance between long-term Fitness (CTL) and short-term Fatigue (ATL).',
        formula: 'TSB_today = Yesterday’s CTL − Yesterday’s ATL',
        details: [
            {
                label: '> +25 (Transition)',
                text: 'Extended rest; fitness may start tapering off.',
            },
            {
                label: '+5 to +25 (Fresh)',
                text: 'Rested and primed for racing or peak performances.',
            },
            {
                label: '−10 to +5 (Grey Zone)',
                text: 'Neutral maintenance balance between fitness and fatigue.',
            },
            {
                label: '−30 to −10 (Optimal Training)',
                text: 'Productive overload building aerobic fitness.',
            },
            {
                label: '< −30 (High Risk)',
                text: 'Deep fatigue overload. Prioritize recovery before hard blocks.',
            },
        ],
    },
    acwr: {
        title: 'ACWR (Acute:Chronic Workload Ratio)',
        summary:
            'Compares short-term Fatigue (7-day ATL) against long-term Fitness (42-day CTL) to flag sudden workload spikes.',
        formula: 'ACWR = ATL ÷ CTL  (evaluated once CTL ≥ 5)',
        details: [
            {
                label: '< 0.80 (Undertraining)',
                text: 'Recent load is well below your 6-week baseline.',
            },
            {
                label: '0.80–1.30 (Sweet Spot)',
                text: 'Optimal zone for steady adaptation with low injury risk.',
            },
            {
                label: '1.30–1.50 (Caution)',
                text: 'Ramping quickly above baseline; monitor recovery signals closely.',
            },
            {
                label: '> 1.50 (High Risk)',
                text: 'Sharp overload spike with elevated injury and overreaching risk.',
            },
        ],
    },
    ramp_rate: {
        title: '7-Day Ramp Rate',
        summary:
            'Measures how many Fitness (CTL) points you gained or lost over the past 7 days.',
        formula: '7d Ramp Rate = CTL_today − CTL_7_days_ago',
        details: [
            {
                label: '+3 to +7 pts/wk',
                text: 'Typical sustainable aerobic build rate.',
            },
            {
                label: '> +8 pts/wk',
                text: 'Aggressive build; watch HRV, sleep, and fatigue closely.',
            },
            {
                label: 'Negative values',
                text: 'Deload, taper, or reduced training volume.',
            },
        ],
    },
    taper_projections: {
        title: 'Taper & Peak Form Projections',
        summary:
            'Simulates how Fitness (CTL), Fatigue (ATL), and Form (TSB) evolve over the next 7–21 days under reduced load so you can time race readiness.',
        details: [
            {
                label: 'Complete Rest',
                text: 'Assumes 0 daily load. ATL drops rapidly and TSB climbs fastest, with slightly more CTL decay.',
            },
            {
                label: 'Maintenance Taper',
                text: 'Assumes 50% of your 28-day average daily load, shedding fatigue while preserving more aerobic fitness.',
            },
            {
                label: 'Peak TSB Date',
                text: 'Highlights the day projected TSB reaches its highest value within the window (once reaching the Fresh ≥ +5 band).',
            },
        ],
    },
    load_sources: {
        title: 'Unified Load Sources',
        summary:
            'Splitline merges multi-sport activities onto a single TSS-equivalent scale using strict per-session priority so CTL/ATL/TSB work across power and HR-only workouts.',
        details: [
            {
                label: '1. TSS (Power)',
                text: 'Used directly whenever power-based Training Stress Score is present.',
            },
            {
                label: '2. Garmin Exercise Load',
                text: 'Used 1:1 as TSS-equivalent points when power TSS is unavailable.',
            },
            {
                label: '3. TRIMP / HR-Zone Load',
                text: 'HR-zone time converted to hrTSS rates (1 hour at threshold ≈ 100), or Banister TRIMP × 0.6 when only average HR exists.',
            },
        ],
        note: 'Garmin EPOC-based Exercise Load can read slightly higher than power TSS on very intense anaerobic sessions.',
    },
    correlation: {
        title: 'Pearson Correlation (r & p-value)',
        summary:
            'Quantifies the linear relationship between two metrics and tests whether the observed trend is unlikely to be random chance.',
        details: [
            {
                label: 'Pearson r (−1.0 to +1.0)',
                text: 'Positive r means both rise together; negative r means one rises as the other falls; 0 means no linear link.',
            },
            {
                label: 'p-value (< 0.05 = significant)',
                text: 'Two-sided Student-t test probability of seeing this correlation by chance. Requires at least 10 paired observations (n ≥ 10).',
            },
            {
                label: 'Strength bands (when p < 0.05)',
                text: '|r| ≥ 0.50 Strong, 0.30–0.49 Moderate, 0.15–0.29 Weak, < 0.15 Negligible. Non-significant results are marked Inconclusive.',
            },
        ],
        note: 'Correlation highlights patterns in your history, but it does not prove direct cause and effect.',
    },
    insights_sleep_performance: {
        title: 'Sleep vs Efficiency & Drift',
        summary:
            "Pairs each training day's primary aerobic session with the preceding night's sleep duration and Sleep Score to test whether better sleep improves Efficiency Factor and lowers cardiac drift.",
        details: [
            {
                label: 'One primary session/day',
                text: 'Multi-session days select one primary aerobic workout so a single night of sleep is never double-counted.',
            },
            {
                label: 'Consistent EF basis',
                text: 'Efficiency Factor is restricted to a single output metric (power or pace/speed) so units remain comparable.',
            },
        ],
    },
    insights_hrv_performance: {
        title: 'HRV & Recovery vs Output',
        summary:
            'Compares overnight HRV status, personal 28-day HRV baseline bands, and Resting HR against same-day aerobic efficiency and cardiac drift.',
        details: [
            {
                label: 'Baseline bands',
                text: 'Uses your stored 28-day balanced HRV bounds (Below, In Baseline, Above), falling back to a trailing 28-day rolling baseline when bounds are absent.',
            },
            {
                label: 'What to watch for',
                text: 'Sessions performed Below Baseline or with elevated Resting HR often show lower EF or higher cardiac drift.',
            },
        ],
    },
    insights_weather_impact: {
        title: 'Temperature & Wind Impact',
        summary:
            'Groups outdoor sessions by ambient temperature bands (< 10°C to ≥ 25°C) and route wind dominance to reveal your personal heat penalty and wind cost.',
        details: [
            {
                label: 'Heat penalty',
                text: 'Warmer temperatures divert blood flow to cooling the skin, raising heart rate for the same output (lower EF and higher cardiac drift).',
            },
            {
                label: 'Wind dominance',
                text: 'Separates Tailwind Dominant, Balanced/Crosswind, and Headwind Dominant outings based on route-projected wind vectors.',
            },
        ],
    },
    insights_workout_recovery: {
        title: 'Workout-to-Recovery Impact',
        summary:
            "Evaluates how workout timing (Morning, Afternoon, Evening) and session intensity affect the following night's sleep quality and overnight HRV change.",
        details: [
            {
                label: 'Next-night HRV Delta',
                text: "Next night's average HRV minus your trailing 7-night HRV baseline (strictly prior nights so the target night doesn't dilute its own baseline).",
            },
            {
                label: 'Positive vs negative delta',
                text: 'Negative ms deltas after hard or late evening sessions indicate stronger autonomic stress carrying into sleep.',
            },
        ],
    },
    eddington: {
        title: 'Eddington Number (E)',
        summary:
            'The largest whole number E such that you have completed at least E days with a total distance of at least E km (or miles).',
        details: [
            {
                label: 'Daily aggregation',
                text: 'Multiple activities on the same local calendar day are summed together toward that day’s total.',
            },
            {
                label: 'Why it gets harder',
                text: 'Moving from E to E + 1 requires E + 1 days of at least E + 1 distance, so earlier days that only reached E no longer count.',
            },
        ],
    },
    race_predictor: {
        title: 'Race Predictor (Riegel Model)',
        summary:
            'Extrapolates equivalent race finish times across 5K, 10K, Half Marathon, and Marathon from your strongest qualifying best effort.',
        formula: 'T₂ = T₁ × (D₂ ÷ D₁)^1.06',
        details: [
            {
                label: 'Source effort selection',
                text: 'Prefers your strongest qualifying best effort from the last 12 months (falling back to all-time qualifying efforts if none exist).',
            },
            {
                label: 'Fatigue exponent (1.06)',
                text: 'Pete Riegel’s endurance decay curve accounts for pace slowing as race distance increases.',
            },
        ],
        note: 'Assumes comparable race-specific training, pacing, terrain, and weather at the target distance. Marathon predictions especially require marathon-specific endurance.',
    },
    gap_vs_raw_pace: {
        title: 'Aerobic Efficiency (GAP vs Raw Pace)',
        summary:
            'Plots Grade-Adjusted Pace alongside raw pace across your sessions so elevation gain does not disguise flat-equivalent speed progression.',
        details: [
            {
                label: 'GAP faster than raw pace',
                text: 'Indicates hilly routes where effort was higher than raw pace alone suggests.',
            },
            {
                label: 'Tracking progress',
                text: 'An upward trend in GAP speed (faster pace) at similar heart rate indicates real aerobic gains independent of terrain.',
            },
        ],
    },
    ftp_wkg: {
        title: 'FTP & Power-to-Weight (W/kg)',
        summary:
            'Functional Threshold Power (FTP) is the highest average power you can sustain for roughly one hour; W/kg divides FTP by body weight.',
        formula: 'W/kg = FTP (W) ÷ Body Weight (kg)',
        details: [
            {
                label: 'Absolute FTP (Watts)',
                text: 'Drives speed on flat roads and time trials where aerodynamic drag dominates.',
            },
            {
                label: 'Relative FTP (W/kg)',
                text: 'Primary determinant of climbing speed where gravity dominates.',
            },
        ],
    },
    weekly_tss: {
        title: 'Weekly TSS',
        summary:
            'Total Training Stress Score accumulated per week (Monday–Sunday), summarizing combined weekly volume and intensity.',
        details: [
            {
                label: 'Consistent progression',
                text: 'Track multi-week ramps and planned recovery weeks to build fitness without burning out.',
            },
        ],
    },
    climb_density: {
        title: 'Avg Climb Density',
        summary:
            'Average vertical elevation climbed per unit of distance (m/km or ft/mi), summarizing how hilly your typical routes are.',
        formula: 'Total Elevation Gain ÷ Total Distance',
        details: [
            { label: '< 10 m/km (~50 ft/mi)', text: 'Mostly flat terrain.' },
            { label: '10–20 m/km (~50–105 ft/mi)', text: 'Rolling hills.' },
            {
                label: '> 20 m/km (~105+ ft/mi)',
                text: 'Hilly or mountainous terrain.',
            },
        ],
    },
    training_readiness: {
        title: 'Training Readiness (Local Estimate)',
        summary:
            'A 1–100 daily readiness estimate modeled locally from 6 weighted recovery and load factors to indicate how prepared your body is for hard training.',
        details: [
            {
                label: 'Last Night’s Sleep (28%)',
                text: 'Overnight sleep score and quality.',
            },
            {
                label: 'Recovery Time (24%)',
                text: 'Simulated residual recovery countdown from recent workouts.',
            },
            {
                label: 'HRV Status (15%)',
                text: 'Overnight HRV relative to your balanced baseline.',
            },
            {
                label: '10d Acute Load (13%)',
                text: 'Decayed 10-day load compared against your 28-day average.',
            },
            {
                label: '3-Night Sleep History (12%)',
                text: 'Blended recent sleep trend over the past 3 nights.',
            },
            {
                label: '3-Day Stress History (8%)',
                text: 'Recent daytime physiological stress levels.',
            },
        ],
        note: 'Also applies penalties for extended awake periods (> 20h) and severe bottlenecks in primary recovery drivers.',
    },
    health_status: {
        title: 'Health Status (Baseline Check)',
        summary:
            'Compares today’s core overnight and resting physiological markers against your personal rolling 28-day baselines.',
        details: [
            {
                label: 'Checked markers',
                text: 'HRV status/baseline, Resting HR, Respiration Rate, and Blood Oxygen (SpO2).',
            },
            {
                label: 'Status levels',
                text: 'In Range (all markers normal), Attention (1 marker outside baseline), Out of Range (2+ markers outside baseline).',
            },
        ],
        note: 'Evaluated locally from your synced history. Out-of-range shifts can reflect heavy training fatigue, poor sleep, travel, or early illness.',
    },
    body_battery: {
        title: 'Body Battery',
        summary:
            'A 0–100 daily energy reserve index combining heart-rate variability, stress, sleep quality, and physical activity.',
        details: [
            {
                label: 'Charging',
                text: 'Rises during restful sleep and low-stress recovery periods.',
            },
            {
                label: 'Draining',
                text: 'Drops during workouts, high daytime stress, or restless nights.',
            },
        ],
    },
    hrv_status: {
        title: 'HRV Status',
        summary:
            'Heart Rate Variability measures millisecond variation between consecutive heartbeats during sleep, reflecting autonomic nervous system recovery.',
        details: [
            {
                label: '7-day average vs 28-day baseline',
                text: 'Balanced means your recent HRV sits inside your personal normal range; Unbalanced, Low, or Poor signal elevated physiological strain.',
            },
            {
                label: 'Individual scale',
                text: 'HRV varies widely between people, so focus on trends against your own baseline rather than comparing raw ms to others.',
            },
        ],
    },
    vo2_max: {
        title: 'VO2 Max',
        summary:
            'Estimated maximum volume of oxygen your body can utilize per minute per kilogram of body weight (mL/kg/min) during intense aerobic exercise.',
        details: [
            {
                label: 'How it is estimated',
                text: 'Derived from the relationship between running pace or cycling power and heart rate during outdoor aerobic efforts.',
            },
            {
                label: 'Why it matters',
                text: 'Higher VO2 max reflects greater cardiorespiratory endurance ceiling and long-term aerobic fitness.',
            },
        ],
    },
    fiets: {
        title: 'FIETS Climb Score & Categories',
        summary:
            'Rates climb difficulty from elevation gain and length so you can compare short steep walls against long mountain passes.',
        formula: 'FIETS = (Elevation Gain_m)² ÷ (Distance_m × 10)',
        details: [
            {
                label: 'Climb categories (Distance_m × Avg Grade %)',
                text: 'Cat 4 ≥ 8,000 · Cat 3 ≥ 16,000 · Cat 2 ≥ 32,000 · Cat 1 ≥ 64,000 · HC ≥ 80,000.',
            },
            {
                label: 'Uncategorized climbs',
                text: 'Auto-detected climbs below Cat 4 still require ≥ 35 m gain and ≥ 3.5% average grade.',
            },
        ],
    },
    vam: {
        title: 'VAM (Mean Ascent Velocity)',
        summary:
            'Velocità Ascensionale Media: your vertical climbing speed measured in meters or feet climbed per hour.',
        formula: 'VAM = Elevation Gain ÷ Elapsed Climbing Hours',
        details: [
            {
                label: 'Why climbers use VAM',
                text: 'Normalizes climbing speed across hills of different lengths; on steady steep grades (> 6%), VAM tracks closely with W/kg.',
            },
        ],
    },
    w_per_kg: {
        title: 'Power-to-Weight (W/kg)',
        summary:
            'Average power output divided by body weight in kilograms, which is the key metric for uphill performance.',
        formula: 'W/kg = Average Power (W) ÷ Body Weight (kg)',
        details: [
            {
                label: 'Why it matters on climbs',
                text: 'Gravity becomes the main resistance on gradients above ~5%, making W/kg a stronger predictor of climb time than raw watts.',
            },
        ],
    },
    explorer_tiles: {
        title: 'Explorer Tiles (z14 Grid)',
        summary:
            'OpenStreetMap Zoom-14 map squares (~2.4 km × 2.4 km at mid-latitudes) crossed by your outdoor GPS routes across all time.',
        details: [
            {
                label: 'Lifetime Tiles',
                text: 'Total unique z14 grid squares ever visited across all outdoor activities (independent of map filters).',
            },
            {
                label: 'Max Square (N×N)',
                text: 'Largest solid square block of adjacent visited tiles with no missing gaps inside.',
            },
            {
                label: 'Max Cluster',
                text: 'Largest connected group of interior tiles (visited squares that are also surrounded on all 4 sides N, S, E, W by visited tiles).',
            },
        ],
    },
    explorer_lifetime_tiles: {
        title: 'Lifetime Explorer Tiles',
        summary:
            'Total unique OpenStreetMap Zoom-14 (~2.4 km) grid squares visited by your outdoor GPS routes across all activities.',
        note: 'Calculated across all lifetime outdoor activities regardless of the current sport or date filters.',
    },
    explorer_max_square: {
        title: 'Max Square',
        summary:
            'The largest solid N×N block of contiguous visited Zoom-14 Explorer Tiles with every single tile inside the square completed.',
        note: 'Even one unvisited tile blocks the square from expanding; filling gaps unlocks the next N×N milestone.',
    },
    explorer_max_cluster: {
        title: 'Max Cluster',
        summary:
            'The largest connected region of interior Explorer Tiles: visited z14 squares surrounded on all 4 cardinal sides (N, S, E, W) by visited tiles.',
        note: 'Rewards dense regional exploration even when coastlines, mountains, or borders prevent forming a neat square.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricGlossaryTopic = keyof typeof METRIC_GLOSSARY;
