<?php
 
require_once("../config.php");

class PriceCalculator {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    //Calculate market condition for a commodity//
    public function getMarketCondition($commodity_id) {
        $recent_sales_sql = "
            SELECT 
                COUNT(*) as total_sales,
                AVG(t.unit_price) as avg_price,
                SUM(t.quantity) as total_quantity
            FROM transactions t
            JOIN batches b ON t.batch_id = b.batch_id
            WHERE b.commodity_id = ? 
            AND t.status = 'Completed'
            AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";
        
        $stmt = $this->conn->prepare($recent_sales_sql);
        $stmt->bind_param("i", $commodity_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result['total_sales'] < 5) return 'normal';
        
        $avg_weekly_sales = $result['total_quantity'] / 7;
        
        // Check current stock
        $stock_sql = "SELECT SUM(current_quantity) as total_stock 
                      FROM batches 
                      WHERE commodity_id = ? AND batch_status = 'Active'";
        $stmt = $this->conn->prepare($stock_sql);
        $stmt->bind_param("i", $commodity_id);
        $stmt->execute();
        $stock = $stmt->get_result()->fetch_assoc()['total_stock'];
        $stmt->close();
        
        // Determine market condition
        if ($stock < ($avg_weekly_sales * 1.5)) return 'high_demand';
        if ($stock > ($avg_weekly_sales * 8)) return 'low_demand';
        
        return 'normal';
    }
    
    //Get seasonality factor for a commodity//
    public function getSeasonalityFactor($commodity_name, $current_month) {
        $seasonal_patterns = [
            'Tomato' => [
                'peak' => [5, 6, 7],    // Summer months
                'factor' => 0.7,
                'off_peak' => [12, 1],
                'off_factor' => 1.3
            ],
            'Mango' => [
                'peak' => [4, 5, 6],
                'factor' => 0.6,
                'off_peak' => [10, 11, 12, 1],
                'off_factor' => 2.0
            ],
            'Potato' => [
                'peak' => [10, 11, 12],
                'factor' => 0.8,
                'off_peak' => [4, 5],
                'off_factor' => 1.5
            ],
            'Cucumber' => [
                'peak' => [6, 7, 8],
                'factor' => 0.75,
                'off_peak' => [12, 1],
                'off_factor' => 1.4
            ],
            'Milk' => [
                'peak' => [5, 6, 7],    // Summer - more consumption
                'factor' => 1.1,
                'off_peak' => [12, 1],
                'off_factor' => 0.9
            ],
            'Rice' => [
                'peak' => [11, 12],     // After harvest
                'factor' => 0.9,
                'off_peak' => [4, 5, 6],
                'off_factor' => 1.2
            ]
        ];
        
        if (isset($seasonal_patterns[$commodity_name])) {
            $pattern = $seasonal_patterns[$commodity_name];
            if (in_array($current_month, $pattern['peak'])) {
                return ['type' => 'peak', 'factor' => $pattern['factor'], 'description' => 'Peak season'];
            } elseif (in_array($current_month, $pattern['off_peak'])) {
                return ['type' => 'off_peak', 'factor' => $pattern['off_factor'], 'description' => 'Off-season'];
            }
        }
        return ['type' => 'normal', 'factor' => 1.0, 'description' => 'Normal season'];
    }
    
    /**
     * Calculate smart price with all market factors
     */
    public function calculateSmartPrice($user_role, $retail_cap, $commodity_id, $commodity_name, $user_id, $batch_id = null) {
        if (!$retail_cap || $retail_cap <= 0) {
            return $this->getFallbackPrice($user_role, $commodity_id, $user_id);
        }
        
        $retail_cap = floatval($retail_cap);
        
        // Base role multipliers
        $base_multipliers = [
            'Farmer' => ['min' => 0.50, 'max' => 0.65, 'avg' => 0.575],
            'Middleman' => ['min' => 0.65, 'max' => 0.80, 'avg' => 0.725],
            'Wholesaler' => ['min' => 0.80, 'max' => 0.92, 'avg' => 0.86],
            'Retailer' => ['min' => 0.92, 'max' => 1.00, 'avg' => 0.96]
        ];
        
        $role_multiplier = $base_multipliers[$user_role] ?? ['min' => 0.70, 'max' => 0.90, 'avg' => 0.80];
        
        // Get market factors
        $market_condition = $this->getMarketCondition($commodity_id);
        $seasonality = $this->getSeasonalityFactor($commodity_name, date('n'));
        
        // Get recent market average
        $market_avg = $this->getMarketAverage($commodity_id, $user_id);
        
        // Get trust score adjustment
        $trust_factor = $this->getTrustFactor($user_id);
        
        // Calculate base price
        $base_price = $retail_cap * $role_multiplier['avg'];
        
        // Apply market adjustments
        $adjusted_price = $this->applyMarketAdjustments($base_price, $market_condition, $seasonality);
        
        // Blend with market average if available
        if ($market_avg > 0) {
            $adjusted_price = $this->blendWithMarketAverage($adjusted_price, $market_avg);
        }
        
        // Apply trust factor
        $adjusted_price *= $trust_factor['factor'];
        
        // Ensure retailer doesn't exceed retail cap
        if ($user_role === 'Retailer') {
            $adjusted_price = min($adjusted_price, $retail_cap);
        }
        
        // Calculate min/max ranges
        $range = $this->calculatePriceRange($adjusted_price, $user_role, $retail_cap);
        
        // Build insights
        $insights = $this->buildPriceInsights(
            $market_condition,
            $seasonality,
            $market_avg,
            $trust_factor,
            $role_multiplier
        );
        
        // Determine confidence
        $confidence = $this->determineConfidence($market_avg, $retail_cap);
        
        return [
            'min' => round($range['min'], 2),
            'max' => round($range['max'], 2),
            'recommended' => round($adjusted_price, 2),
            'market_avg' => round($market_avg, 2),
            'retail_cap' => round($retail_cap, 2),
            'market_condition' => $market_condition,
            'seasonality' => $seasonality,
            'trust_factor' => $trust_factor,
            'insights' => $insights,
            'confidence' => $confidence,
            'note' => $insights['note']
        ];
    }
    
    /**
     * Get fallback price when no retail cap exists
     */
    private function getFallbackPrice($user_role, $commodity_id, $user_id) {
        $market_avg = $this->getMarketAverage($commodity_id, $user_id);
        
        if ($market_avg > 0) {
            $role_adjustments = [
                'Farmer' => 0.85,
                'Middleman' => 0.95,
                'Wholesaler' => 1.05,
                'Retailer' => 1.15
            ];
            
            $adjustment = $role_adjustments[$user_role] ?? 1.0;
            $recommended = $market_avg * $adjustment;
            
            return [
                'min' => round($recommended * 0.85, 2),
                'max' => round($recommended * 1.15, 2),
                'recommended' => round($recommended, 2),
                'market_avg' => round($market_avg, 2),
                'retail_cap' => 0,
                'market_condition' => 'normal',
                'seasonality' => ['type' => 'normal', 'factor' => 1.0, 'description' => 'Normal season'],
                'trust_factor' => ['score' => 100, 'factor' => 1.0],
                'insights' => ['factors' => ['Market average only'], 'note' => 'No retail cap set. Using market average.'],
                'confidence' => 'medium',
                'note' => 'No retail price cap available. Price based on recent market average.'
            ];
        }
        
        // No data available
        return [
            'min' => 0,
            'max' => 0,
            'recommended' => 0,
            'market_avg' => 0,
            'retail_cap' => 0,
            'market_condition' => 'unknown',
            'seasonality' => ['type' => 'normal', 'factor' => 1.0, 'description' => 'Normal season'],
            'trust_factor' => ['score' => 100, 'factor' => 1.0],
            'insights' => ['factors' => [], 'note' => 'Insufficient market data'],
            'confidence' => 'low',
            'note' => 'No pricing data available. Please check market conditions.'
        ];
    }
    
    /**
     * Get market average price
     */
    private function getMarketAverage($commodity_id, $exclude_user_id) {
        $sql = "
            SELECT AVG(t.unit_price) as market_avg,
                   COUNT(*) as sample_size
            FROM transactions t
            JOIN batches b ON t.batch_id = b.batch_id
            WHERE b.commodity_id = ? 
            AND t.status = 'Completed'
            AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND t.seller_id != ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $commodity_id, $exclude_user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return ($result['sample_size'] >= 3) ? ($result['market_avg'] ?? 0) : 0;
    }
    
    /**
     * Get trust factor based on user's trust score
     */
    private function getTrustFactor($user_id) {
        $sql = "SELECT trust_score FROM users WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $trust_score = $result['trust_score'] ?? 50;
        
        // Higher trust = can charge more (up to +10%)
        // Lower trust = should charge less (down to -10%)
        $factor = 1.0 + (($trust_score - 50) / 500);
        
        return ['score' => $trust_score, 'factor' => $factor];
    }
    
    /**
     * Apply market condition and seasonality adjustments
     */
    private function applyMarketAdjustments($base_price, $market_condition, $seasonality) {
        $market_adjustments = [
            'normal' => 1.0,
            'high_demand' => 1.15,
            'low_demand' => 0.85
        ];
        
        $market_factor = $market_adjustments[$market_condition] ?? 1.0;
        $season_factor = $seasonality['factor'];
        
        return $base_price * $market_factor * $season_factor;
    }
    
    /**
     * Blend calculated price with market average
     */
    private function blendWithMarketAverage($calculated_price, $market_avg) {
        // Weight: 60% market average, 40% calculated price
        return ($market_avg * 0.6) + ($calculated_price * 0.4);
    }
    
    /**
     * Calculate price range based on recommended price
     */
    private function calculatePriceRange($recommended_price, $user_role, $retail_cap) {
        $range_percent = 0.15; // 15% range
        
        $min_price = $recommended_price * (1 - $range_percent);
        $max_price = $recommended_price * (1 + $range_percent);
        
        // Retailers cannot exceed retail cap
        if ($user_role === 'Retailer') {
            $max_price = min($max_price, $retail_cap);
        }
        
        return [
            'min' => max($min_price, $recommended_price * 0.5), // At least 50% of recommended
            'max' => $max_price
        ];
    }
    
    /**
     * Build price insights description
     */
    private function buildPriceInsights($market_condition, $seasonality, $market_avg, $trust_factor, $role_multiplier) {
        $factors = [];
        
        if ($market_condition !== 'normal') {
            $factors[] = ucfirst(str_replace('_', ' ', $market_condition));
        }
        
        if ($seasonality['type'] !== 'normal') {
            $factors[] = $seasonality['description'];
        }
        
        if ($market_avg > 0) {
            $factors[] = "market trends";
        }
        
        $factors[] = "role-based pricing";
        
        if ($trust_factor['score'] > 70) {
            $factors[] = "good reputation";
        } elseif ($trust_factor['score'] < 30) {
            $factors[] = "low reputation";
        }
        
        $note = "Price considers: " . implode(', ', $factors);
        
        return [
            'factors' => $factors,
            'note' => $note
        ];
    }
    
    /**
     * Determine confidence level
     */
    private function determineConfidence($market_avg, $retail_cap) {
        if ($market_avg > 0 && $retail_cap > 0) return 'high';
        if ($market_avg > 0 || $retail_cap > 0) return 'medium';
        return 'low';
    }
    
    /**
     * Check for hoarding violations
     */
    public function checkHoardingViolation($user_id, $commodity_id) {
        // Get total stock held by user
        $stock_sql = "SELECT SUM(current_quantity) as total_stock 
                      FROM batches 
                      WHERE owner_id = ? AND commodity_id = ? AND batch_status = 'Active'";
        $stmt = $this->conn->prepare($stock_sql);
        $stmt->bind_param("ii", $user_id, $commodity_id);
        $stmt->execute();
        $current_stock = $stmt->get_result()->fetch_assoc()['total_stock'] ?? 0;
        $stmt->close();
        
        if ($current_stock <= 0) return ['violation' => false];
        
        // Get average daily sales
        $sales_sql = "
            SELECT IFNULL(AVG(daily_sales), 0) as avg_daily_sales 
            FROM (
                SELECT SUM(t.quantity) as daily_sales
                FROM transactions t
                JOIN batches b ON t.batch_id = b.batch_id
                WHERE t.seller_id = ? AND b.commodity_id = ?
                AND t.status = 'Completed'
                AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(t.transaction_date)
            ) as daily_stats
        ";
        
        $stmt = $this->conn->prepare($sales_sql);
        $stmt->bind_param("ii", $user_id, $commodity_id);
        $stmt->execute();
        $avg_daily_sales = $stmt->get_result()->fetch_assoc()['avg_daily_sales'] ?? 0;
        $stmt->close();
        
        if ($avg_daily_sales <= 0) return ['violation' => false];
        
        $days_supply = $current_stock / $avg_daily_sales;
        $max_allowed_days = 15; // Maximum days of supply allowed
        
        if ($days_supply > $max_allowed_days) {
            return [
                'violation' => true,
                'reason' => "Potential hoarding detected",
                'severity' => $days_supply > 30 ? 'high' : 'medium',
                'details' => [
                    'current_stock' => round($current_stock, 2),
                    'avg_daily_sales' => round($avg_daily_sales, 2),
                    'days_supply' => round($days_supply, 1),
                    'max_allowed' => $max_allowed_days
                ]
            ];
        }
        
        return ['violation' => false];
    }
    
    /**
     * Check if price violates retail cap
     */
    public function checkPriceViolation($user_role, $unit_price, $retail_cap) {
        if ($user_role !== 'Retailer' || !$retail_cap || $retail_cap <= 0) {
            return ['violation' => false];
        }
        
        $unit_price = floatval($unit_price);
        $retail_cap = floatval($retail_cap);
        
        if ($unit_price > $retail_cap) {
            $excess_percent = (($unit_price - $retail_cap) / $retail_cap) * 100;
            
            return [
                'violation' => true,
                'reason' => "Exceeds retail price cap",
                'details' => [
                    'your_price' => $unit_price,
                    'retail_cap' => $retail_cap,
                    'excess_amount' => $unit_price - $retail_cap,
                    'excess_percent' => round($excess_percent, 1)
                ],
                'penalty' => $excess_percent > 20 ? 25 : 20 // Higher penalty for extreme violations
            ];
        }
        
        return ['violation' => false];
    }
    
    /**
     * Get price deviation warning
     */
    public function getPriceDeviationWarning($unit_price, $recommended_price) {
        if ($recommended_price <= 0) return null;
        
        $deviation = abs(($unit_price - $recommended_price) / $recommended_price) * 100;
        
        if ($deviation > 100) {
            return [
                'level' => 'danger',
                'message' => "Extreme price deviation: " . round($deviation, 1) . "% from market recommendation",
                'action' => "This may trigger investigation"
            ];
        } elseif ($deviation > 50) {
            return [
                'level' => 'warning',
                'message' => "High price deviation: " . round($deviation, 1) . "% from recommendation",
                'action' => "Consider market rates"
            ];
        } elseif ($deviation > 20) {
            return [
                'level' => 'info',
                'message' => "Price deviation: " . round($deviation, 1) . "% from recommendation",
                'action' => "Within acceptable range"
            ];
        }
        
        return null;
    }
    
    /**
     * Get market summary for dashboard
     */
    public function getMarketSummary($commodity_id = null) {
        $summary = [
            'total_commodities' => 0,
            'active_caps' => 0,
            'recent_transactions' => 0,
            'avg_price_trend' => 0
        ];
        
        // Count commodities
        $sql = "SELECT COUNT(*) as count FROM commodities WHERE status = 'Active'";
        $result = $this->conn->query($sql);
        $summary['total_commodities'] = $result->fetch_assoc()['count'];
        
        // Count active price caps
        $sql = "SELECT COUNT(*) as count FROM price_caps 
                WHERE expiry_date >= CURDATE() OR expiry_date IS NULL";
        $result = $this->conn->query($sql);
        $summary['active_caps'] = $result->fetch_assoc()['count'];
        
        // Recent transactions
        $sql = "SELECT COUNT(*) as count FROM transactions 
                WHERE status = 'Completed' 
                AND transaction_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $result = $this->conn->query($sql);
        $summary['recent_transactions'] = $result->fetch_assoc()['count'];
        
        // Average price trend
        $sql = "SELECT AVG(unit_price) as avg_price FROM transactions 
                WHERE status = 'Completed' 
                AND transaction_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $result = $this->conn->query($sql);
        $summary['avg_price_trend'] = round($result->fetch_assoc()['avg_price'] ?? 0, 2);
        
        return $summary;
    }
}
?>