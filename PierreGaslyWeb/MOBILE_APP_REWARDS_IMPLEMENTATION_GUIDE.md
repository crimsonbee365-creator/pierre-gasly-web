# PIERRE GASLY REWARDS SYSTEM V2.0 - MOBILE APP IMPLEMENTATION GUIDE

## 📋 EXECUTIVE SUMMARY

The web version has been completely overhauled from a percentage-based bonus system to a **fixed-discount tier system with lifetime points tracking**. This document provides everything you need to implement the new rewards system in the mobile app.

---

## 🎯 KEY CONCEPTS

### 1. **Two Types of Points**

#### **Lifetime Points**
- **Definition**: Total points EVER earned by the customer
- **Behavior**: ONLY increases, NEVER decreases
- **Purpose**: Determines customer tier
- **Updates**: Increases when customer earns points from orders
- **Important**: Does NOT decrease when points are redeemed

#### **Redeemable Points (Available Points)**
- **Definition**: Current points available for redemption
- **Calculation**: `total_points - redeemed_points`
- **Behavior**: Increases when earned, decreases when redeemed
- **Purpose**: Used for redemption discounts at checkout
- **Important**: Does NOT affect tier status

### 2. **Example Scenario**
```
Initial State:
- Lifetime Points: 2,500
- Total Points: 2,500
- Redeemed Points: 0
- Available Points: 2,500
- Tier: Silver (unlocked at 1,800)

Customer redeems 1,000 points:
- Lifetime Points: 2,500 ← STAYS THE SAME!
- Total Points: 2,500 ← STAYS THE SAME!
- Redeemed Points: 1,000 ← INCREASED
- Available Points: 1,500 ← DECREASED (2500 - 1000)
- Tier: Silver ← STAYS THE SAME!

Customer earns 500 new points:
- Lifetime Points: 3,000 ← INCREASED
- Total Points: 3,000 ← INCREASED
- Redeemed Points: 1,000 ← STAYS THE SAME
- Available Points: 2,000 ← INCREASED (3000 - 1000)
- Tier: Silver (will upgrade to Gold at 3,300)
```

---

## 🏆 TIER SYSTEM

### Tier Structure & Benefits

| Tier | Unlock Requirement | Auto Discount | Redemption Option | Special Benefit |
|------|-------------------|---------------|-------------------|-----------------|
| **Bronze** | 0 points (signup) | 0% | 500 pts = ₱40 | Base tier |
| **Silver** | 1,800 lifetime pts | 2% | 1,000 pts = ₱90 | - |
| **Gold** | 3,300 lifetime pts | 3% | 1,500 pts = ₱140 | - |
| **Platinum** | 7,000 lifetime pts | 4% | 2,000 pts = ₱190 | Free delivery* |

*Free delivery available when order meets cluster requirements (explained below)

### Tier Colors for UI
```
Bronze:   Linear gradient from #d97706 to #92400e
Silver:   Linear gradient from #94a3b8 to #475569
Gold:     Linear gradient from #f59e0b to #d97706
Platinum: Linear gradient from #8b5cf6 to #5b21b6
```

---

## 💰 POINTS EARNING

### Base Points Per Product

| Product Type | Points Earned |
|-------------|---------------|
| 7kg LPG Purchase | 60 |
| 7kg Refill | 50 |
| 11kg LPG Purchase | 100 |
| 11kg Refill | 90 |
| 22kg LPG Purchase | 210 |
| 22kg Refill | 200 |
| Above 22kg LPG Purchase | 250 |
| Above 22kg Refill | 220 |

**Note**: Points are multiplied by quantity ordered.

**Example**: Ordering 3x 11kg LPG = 100 pts × 3 = 300 points earned

---

## 🎁 REDEMPTION SYSTEM

### Critical Rules

1. **Tier-Based Restrictions**
   - Customers can ONLY use their current tier's redemption option
   - Bronze can't use Silver/Gold/Platinum redemptions
   - Silver can't use Gold/Platinum redemptions
   - Gold can't use Platinum redemption
   - Each tier has progressively better redemption value

2. **One Redemption Per Order**
   - Customer can only redeem once per checkout
   - Cannot combine multiple redemption options

3. **Points Deduction**
   - Points deducted from **redeemable points** only
   - **Lifetime points NEVER decrease**
   - Tier status remains unchanged

### Redemption Options by Tier

**Bronze Tier:**
- Spend: 500 points
- Get: ₱40 discount
- Value: ₱0.08 per point

**Silver Tier:**
- Spend: 1,000 points
- Get: ₱90 discount
- Value: ₱0.09 per point

**Gold Tier:**
- Spend: 1,500 points
- Get: ₱140 discount
- Value: ₱0.093 per point

**Platinum Tier:**
- Spend: 2,000 points
- Get: ₱190 discount
- Value: ₱0.095 per point

---

## 🚚 FREE DELIVERY (PLATINUM ONLY)

### How Free Delivery Works

**Free Credits Calculation:**
```javascript
// Per product in cart
if (isRefill) {
  freeCredits = 0.5 × quantity
} else {
  freeCredits = 1.0 × quantity
}

// Total free credits for order
totalFreeCredits = sum of all products' free credits
```

**Cluster Requirements:**
- **Tier 1 (Cluster 1)**: Needs 3 free credits
- **Tier 2 (Cluster 2)**: Needs 5 free credits
- **Tier 3 (Cluster 3)**: Needs 10 free credits

**Free Delivery Applied When:**
1. Customer tier = Platinum
2. Fulfillment method = Delivery (not pickup)
3. Free credits from order ≥ cluster requirement

**Important:**
- Free credits DO NOT accumulate across orders
- Calculated fresh for each order only
- Must meet requirement in SINGLE order

### Example Scenarios

**Example 1 - Free Delivery Granted:**
```
Customer: Platinum tier
Delivery: Tier 1 (requires 3 credits)
Order: 2x 11kg LPG + 1x 7kg Refill
Free Credits: (2 × 1.0) + (1 × 0.5) = 2.5 credits
Result: NOT ENOUGH (needs 3), delivery fee applies
```

**Example 2 - Free Delivery Granted:**
```
Customer: Platinum tier
Delivery: Tier 1 (requires 3 credits)
Order: 3x 11kg LPG
Free Credits: 3 × 1.0 = 3 credits
Result: FREE DELIVERY! ✓
```

**Example 3 - Higher Cluster:**
```
Customer: Platinum tier
Delivery: Tier 2 (requires 5 credits)
Order: 6x 7kg Refill
Free Credits: 6 × 0.5 = 3 credits
Result: NOT ENOUGH (needs 5), delivery fee applies
```

---

## 💳 CHECKOUT CALCULATION FLOW

### Step-by-Step Calculation

```
1. Calculate Subtotal
   subtotal = price × quantity

2. Apply Tier Discount (Automatic)
   tier_discount_amount = subtotal × (tier_discount_percent / 100)
   
   Bronze: 0%
   Silver: 2%
   Gold: 3%
   Platinum: 4%

3. Apply Redemption Discount (If customer opts in)
   IF use_points == true:
     - Check: available_points >= required_points
     - Deduct: required_points from available_points
     - Apply: redemption_discount_amount
   
4. Calculate Delivery Fee
   delivery_fee = based on delivery tier
   
5. Apply Free Delivery (Platinum only)
   IF tier == "Platinum" AND free_credits >= cluster_requirement:
     delivery_fee = 0

6. Calculate Final Total
   total = subtotal - tier_discount_amount - redemption_discount_amount + delivery_fee

7. Calculate Points Earned
   points_earned = base_points_for_product × quantity
   
8. Update Points
   new_total_points = total_points + points_earned
   new_lifetime_points = lifetime_points + points_earned
   new_redeemed_points = redeemed_points + points_redeemed
   new_available_points = new_total_points - new_redeemed_points
   
9. Update Tier
   new_tier = calculate_tier_from_lifetime_points(new_lifetime_points)
```

### Complete Example

```
Customer Profile:
- Tier: Silver
- Lifetime Points: 2,100
- Total Points: 2,100
- Redeemed Points: 0
- Available Points: 2,100

Order Details:
- Product: 11kg LPG (₱1,000)
- Quantity: 2
- Delivery Tier: Tier 1 (₱50 fee)
- Use Points: YES (Silver redemption)

Calculation:
1. Subtotal = ₱1,000 × 2 = ₱2,000

2. Tier Discount (Silver = 2%)
   = ₱2,000 × 0.02 = ₱40

3. Redemption (Silver: 1,000 pts = ₱90)
   - Has 2,100 available points ✓
   - Deduct 1,000 points
   - Apply ₱90 discount

4. Delivery Fee = ₱50

5. Free Delivery Check:
   - Tier is Silver (not Platinum) ✗
   - No free delivery

6. Final Total:
   = ₱2,000 - ₱40 - ₱90 + ₱50
   = ₱1,920

7. Points Earned:
   = 100 pts × 2 = 200 pts

8. Updated Points:
   - Total Points: 2,100 + 200 = 2,300
   - Lifetime Points: 2,100 + 200 = 2,300
   - Redeemed Points: 0 + 1,000 = 1,000
   - Available Points: 2,300 - 1,000 = 1,300

9. Tier Status:
   - 2,300 < 3,300 (Gold threshold)
   - Remains: Silver
```

---

## 📱 MOBILE APP API INTEGRATION

### 1. Fetch Rewards Data

**Endpoint:** `GET /api/rewards/get.php`

**Headers:**
```json
{
  "Authorization": "Bearer {user_token}"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Rewards fetched",
  "data": {
    "tier": "Silver",
    "total_points": 2100,
    "redeemed_points": 0,
    "available_points": 2100,
    "lifetime_points": 2100,
    "tier_discount_pct": 2,
    "redemption_rule": {
      "points": 1000,
      "value": 90
    },
    "next_tier": "Gold",
    "points_to_next_tier": 1200,
    "progress_pct": 25,
    "points_enabled": true,
    "rewards_enabled": true,
    "one_redemption_per_order": true,
    "stacks_with_redemption": true,
    "free_delivery_rules": {
      "cluster_1": 3,
      "cluster_2": 5,
      "cluster_3": 10,
      "lpg_credit": 1,
      "refill_credit": 0.5
    },
    "program": {
      "purchase_7kg_points": 60,
      "refill_7kg_points": 50,
      "purchase_11kg_points": 100,
      "refill_11kg_points": 90,
      "purchase_22kg_points": 210,
      "refill_22kg_points": 200,
      "purchase_above_22kg_points": 250,
      "refill_above_22kg_points": 220
    },
    "history": [
      {
        "tx_id": 123,
        "points": 200,
        "type": "earned",
        "description": "Points earned for order #PG-20260322-ABC123",
        "created_at": "2026-03-22T10:30:00Z"
      }
    ]
  }
}
```

### 2. Place Order with Rewards

**Endpoint:** `POST /api/orders/place.php`

**Headers:**
```json
{
  "Authorization": "Bearer {user_token}",
  "Content-Type": "application/json"
}
```

**Request Body:**
```json
{
  "product_id": 5,
  "quantity": 2,
  "barangay_street": "123 Main St",
  "city_town": "Dagupan City",
  "province": "Pangasinan",
  "contact_number": "9123456789",
  "fulfillment_method": "cod",
  "use_points": true,
  "delivery_notes": "Gate code: 1234"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order placed successfully!",
  "data": {
    "order_id": 456,
    "order_number": "PG-20260322-ABC123",
    "subtotal": 2000,
    "delivery_fee": 50,
    "delivery_tier": "Tier 1",
    "tier_discount_percent": 2,
    "tier_discount_amount": 40,
    "redemption_discount_amount": 90,
    "points_redeemed": 1000,
    "free_credits_earned": 2,
    "free_delivery_applied": false,
    "reward_points_earned": 200,
    "customer_tier": "Silver",
    "lifetime_points_after": 2300,
    "available_points_after": 1300,
    "total_amount": 1920,
    "status": "pending",
    "fulfillment_method": "cod",
    "payment_method": "cod"
  }
}
```

---

## 🎨 MOBILE APP UI/UX REQUIREMENTS

### 1. Profile / Rewards Screen

**Must Display:**
- Current tier badge with gradient background
- Tier name and benefits
- Lifetime points (for tier progression)
- Available points (for redemption)
- Progress bar to next tier with percentage
- "X points to [Next Tier]" message
- Current tier benefits list
- Redemption option for current tier
- Transaction history

**Example Layout:**
```
┌─────────────────────────────────────┐
│  [Silver Tier Badge - Gradient]    │
│  Silver Member                      │
│  ────────────────────────────────   │
│  Lifetime Points: 2,300             │
│  Available Points: 1,300            │
│                                     │
│  Progress to Gold                   │
│  [█████████░░░] 25%                 │
│  1,000 more points to unlock        │
│                                     │
│  Your Benefits:                     │
│  • 2% automatic discount            │
│  • Redeem 1,000 pts = ₱90          │
│                                     │
│  Recent Transactions                │
│  [Transaction List]                 │
└─────────────────────────────────────┘
```

### 2. Checkout Screen

**Must Display:**
- Current tier badge (compact)
- Available points
- Subtotal
- Tier discount (auto-applied, show amount)
- Redemption toggle/checkbox
  - Only show if enough points
  - Show redemption details: "Use 1,000 pts for ₱90 off"
- Delivery fee
- Free delivery indicator (Platinum only)
  - Show free credits calculation
  - Show if requirements met
- Total
- Points to be earned from order

**Example Layout:**
```
┌─────────────────────────────────────┐
│  Checkout                           │
│  ────────────────────────────────   │
│  [Silver Badge] 1,300 points avail  │
│                                     │
│  Subtotal:              ₱2,000.00   │
│  Tier Discount (2%):     -₱40.00    │
│                                     │
│  ☐ Use 1,000 points for ₱90 off    │
│    (You have 1,300 available)       │
│                                     │
│  Delivery Fee:             ₱50.00   │
│  ────────────────────────────────   │
│  Total:                 ₱2,010.00   │
│                                     │
│  💎 Earn 200 points from this order │
│                                     │
│  [Place Order]                      │
└─────────────────────────────────────┘
```

### 3. Platinum Free Delivery Indicator

When customer is Platinum and viewing cart:
```
┌─────────────────────────────────────┐
│  🚚 Free Delivery Status            │
│  ────────────────────────────────   │
│  Your cart: 2.0 free credits        │
│  Tier 1 requires: 3.0 credits       │
│                                     │
│  Add 1 more item for free delivery! │
│                                     │
│  OR                                 │
│                                     │
│  ✓ Free delivery unlocked!          │
│  Your cart qualifies: 3.5 credits   │
└─────────────────────────────────────┘
```

---

## 🔧 IMPLEMENTATION CHECKLIST

### Backend/API
- [ ] Update API calls to use `/api/rewards/get.php`
- [ ] Parse new response structure with lifetime points
- [ ] Update order placement to include `use_points` parameter
- [ ] Handle new order response with rewards details
- [ ] Store tier information locally after fetch
- [ ] Implement token refresh if needed

### Profile/Rewards Screen
- [ ] Display tier badge with correct gradient colors
- [ ] Show lifetime points (not just total points)
- [ ] Show available points calculation
- [ ] Implement progress bar to next tier
- [ ] Display current tier benefits
- [ ] Show tier-specific redemption option only
- [ ] List transaction history
- [ ] Add tier benefit explanations

### Checkout Screen
- [ ] Display current tier badge/indicator
- [ ] Show automatic tier discount amount
- [ ] Add redemption toggle/checkbox
- [ ] Validate available points before enabling redemption
- [ ] Display tier-specific redemption details
- [ ] Show free delivery calculation (Platinum only)
- [ ] Display free credits and requirements
- [ ] Show points to be earned from order
- [ ] Calculate total correctly with all discounts

### Cart/Product Screens
- [ ] Show points earning per product
- [ ] Display free credits per product (Platinum)
- [ ] Running total of free credits in cart
- [ ] Free delivery eligibility indicator

### Calculations
- [ ] Implement tier discount calculation
- [ ] Implement redemption discount validation
- [ ] Implement free credits calculation
- [ ] Implement free delivery logic
- [ ] Implement tier upgrade detection
- [ ] Handle edge cases (insufficient points, etc.)

### Testing
- [ ] Test Bronze tier checkout
- [ ] Test Silver tier with redemption
- [ ] Test Gold tier with redemption
- [ ] Test Platinum tier with free delivery
- [ ] Test tier upgrades after order
- [ ] Test insufficient points scenario
- [ ] Test free delivery requirements
- [ ] Test points earning calculation
- [ ] Test lifetime vs available points display

---

## ⚠️ CRITICAL IMPLEMENTATION NOTES

### 1. Lifetime Points are Sacred
```javascript
// ❌ WRONG - Never do this
lifetimePoints = lifetimePoints - pointsRedeemed

// ✓ CORRECT - Lifetime points only increase
lifetimePoints = lifetimePoints + pointsEarned

// ✓ CORRECT - Redemptions affect available points
availablePoints = totalPoints - redeemedPoints
```

### 2. Tier Determination
```javascript
// ✓ ALWAYS use lifetime points for tier
function getTier(lifetimePoints) {
  if (lifetimePoints >= 7000) return 'Platinum'
  if (lifetimePoints >= 3300) return 'Gold'
  if (lifetimePoints >= 1800) return 'Silver'
  return 'Bronze'
}

// ❌ NEVER use available points for tier
// tier = getTier(availablePoints) // WRONG!
```

### 3. Redemption Validation
```javascript
// ✓ CORRECT validation flow
function canRedeem(tier, availablePoints, redemptionRules) {
  const rule = redemptionRules[tier]
  return availablePoints >= rule.points
}

// Show redemption option only if eligible
if (canRedeem(currentTier, availablePoints, redemptionRules)) {
  // Display redemption checkbox
  // Show: "Use ${rule.points} points for ₱${rule.value} off"
}
```

### 4. Free Delivery Calculation
```javascript
function calculateFreeCredits(cartItems, settings) {
  let credits = 0
  
  for (const item of cartItems) {
    const isRefill = item.name.toLowerCase().includes('refill')
    const creditPerUnit = isRefill 
      ? settings.refill_free_credit_value 
      : settings.lpg_free_credit_value
    
    credits += creditPerUnit * item.quantity
  }
  
  return credits
}

function canGetFreeDelivery(tier, freeCredits, cluster, settings) {
  if (tier !== 'Platinum') return false
  
  const required = settings.free_delivery_rules[`cluster_${cluster}`]
  return freeCredits >= required
}
```

---

## 🐛 COMMON PITFALLS TO AVOID

### 1. Using Wrong Points for Tier
```javascript
// ❌ WRONG
const tier = getTier(user.availablePoints)

// ✓ CORRECT
const tier = getTier(user.lifetimePoints)
```

### 2. Showing Wrong Redemption Options
```javascript
// ❌ WRONG - Showing all redemption options
redemptionOptions = [bronze, silver, gold, platinum]

// ✓ CORRECT - Show only current tier option
redemptionOption = redemptionRules[currentTier]
```

### 3. Decreasing Lifetime Points
```javascript
// ❌ WRONG
user.lifetimePoints -= pointsRedeemed

// ✓ CORRECT - Only redeemed_points increases
user.redeemedPoints += pointsRedeemed
user.availablePoints = user.totalPoints - user.redeemedPoints
// lifetimePoints stays unchanged
```

### 4. Accumulating Free Credits Across Orders
```javascript
// ❌ WRONG
savedCredits += orderCredits
if (savedCredits >= requirement) applyFreeDelivery()

// ✓ CORRECT - Calculate per order only
const orderCredits = calculateFreeCredits(currentOrder)
if (orderCredits >= requirement) applyFreeDelivery()
```

### 5. Not Validating Before Redemption
```javascript
// ❌ WRONG
applyRedemption(redemptionRule)

// ✓ CORRECT
if (availablePoints >= redemptionRule.points) {
  applyRedemption(redemptionRule)
} else {
  showError('Insufficient points for redemption')
}
```

---

## 📊 ANALYTICS TO TRACK

### Key Metrics
1. **Tier Distribution**
   - % of users in each tier
   - Tier migration rate

2. **Redemption Rate**
   - % of orders using redemption
   - Average points redeemed per order

3. **Free Delivery Usage**
   - % of Platinum orders with free delivery
   - Average free credits per order

4. **Points Engagement**
   - Average lifetime points per user
   - Points earned vs redeemed ratio

---

## 🎉 BENEFITS SUMMARY

### For Customers
✅ Clear tier progression system
✅ Automatic discounts (no manual effort)
✅ Better redemption value at higher tiers
✅ Free delivery benefit at Platinum
✅ Lifetime achievement tracking

### For Business
✅ Encourages customer loyalty
✅ Incentivizes larger orders (free delivery)
✅ Clear progression path keeps customers engaged
✅ Professional, modern rewards system
✅ Easy to explain and understand

---

## 📞 SUPPORT & QUESTIONS

If you encounter any issues during implementation:

1. **Check API Response Format**: Ensure you're parsing the correct fields
2. **Verify Calculations**: Test with known scenarios
3. **Review This Guide**: Common issues are documented above
4. **Test Edge Cases**: Insufficient points, tier boundaries, etc.

---

**Document Version**: 1.0
**Last Updated**: March 22, 2026
**Status**: Ready for Mobile Implementation
