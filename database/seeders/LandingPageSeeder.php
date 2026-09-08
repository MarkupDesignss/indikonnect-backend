<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LandingPage;

class LandingPageSeeder extends Seeder
{
    public function run()
    {
        $sections = [
            // Hero Section
            [
                'section' => 'hero',
                'section_title' => 'Art of',
                'section_subtitle' => 'Opportunity',
                'description' => 'A modern Indian movement built on Connection, Opportunity, Growth and Trust, where the spirit of 1.4 billion meets the power of entrepreneurship.',
                'order' => 1,
            ],

            // Chapter One - The Nation
            [
                'section' => 'chapter_one',
                'section_title' => 'CHAPTER ONE · THE NATION',
                'description' => '0.0 billion people. One shared ambition. Before IndieKonnect was a brand, it was an observation: India does not lack talent, it lacks doorways.',
                'order' => 2,
            ],

            // Chapter Two - The Meaning
            [
                'section' => 'chapter_two',
                'section_title' => 'CHAPTER TWO · THE MEANING',
                'description' => 'So we built a doorway, and gave it a name. Two ideas, one identity. The independent spirit of India, bridged to the aspirations of every entrepreneur who dares to rise.',
                'order' => 3,
            ],

            // Brand Identity - Part One
            [
                'section' => 'brand_identity_part_one',
                'section_title' => 'PART ONE',
                'description' => 'The independent spirit of India. Its culture, its people, and an ambition that has never asked permission to exist.',
                'order' => 4,
            ],

            // Brand Identity - IndieKonnect
            [
                'section' => 'brand_indiekonnect',
                'section_title' => 'INDIEKONNECT',
                'description' => 'The brand is the identity. An institution built not around individuals, but a collective vision of excellence.',
                'order' => 5,
            ],

            // Brand Identity - Part Two
            [
                'section' => 'brand_identity_part_two',
                'section_title' => 'PART TWO',
                'description' => 'The bridge of opportunity. Our mission to close the distance between world-class products and the aspiring Indian entrepreneur.',
                'order' => 6,
            ],

            // Chapter Three - Collections
            [
                'section' => 'chapter_three',
                'section_title' => 'CHAPTER THREE - THE COLLECTIONS',
                'section_subtitle' => 'Curated for the discerning',
                'description' => 'KEEP SCROLLING',
                'order' => 7,
                'content_data' => [
                    'products' => [
                        [
                            'id' => 1,
                            'category' => 'BEST SELLER',
                            'name' => 'Celeste Necklace',
                            'price' => '₹ 8,499',
                            'image' => 'celeste-necklace.jpg'
                        ],
                        [
                            'id' => 2,
                            'category' => 'ELEGANT JEWELLERY',
                            'name' => 'Nova Dinner Set',
                            'price' => '₹ 6,999',
                            'image' => 'nova-dinner-set.jpg'
                        ],
                        [
                            'id' => 3,
                            'category' => 'TIMELESS HOROLOGY',
                            'name' => 'Aurelia Chronograph',
                            'price' => '₹ 12,999',
                            'image' => 'aurelia-chronograph.jpg'
                        ],
                        [
                            'id' => 4,
                            'category' => 'MERIDIAN AUTOMATIC',
                            'name' => 'Radiance Serum',
                            'price' => '₹ 18,499',
                            'image' => 'radiance-serum.jpg'
                        ]
                    ]
                ]
            ],

            // Chapter Four - The Standard
            [
                'section' => 'chapter_four',
                'section_title' => 'CHAPTER FOUR: THE STANDARD',
                'section_subtitle' => 'Made to be kept',
                'description' => 'Watches drawn from heritage horology. Jewellery that reflects individuality. Skincare built on performance, not promises. Dining pieces that bring elegance to the modern Indian home. Four categories, one standard.',
                'order' => 8,
            ],

            // Values Section
            [
                'section' => 'values',
                'section_title' => 'values you can trust',
                'description' => 'The IndieKonnect ecosystem runs on a people-first philosophy. Our house colours are not decoration, they are a commitment.',
                'order' => 9,
                'content_data' => [
                    'values' => [
                        [
                            'title' => 'Trust & Stability',
                            'color' => 'Blue',
                            'description' => 'We operate with professional vision and steady confidence in every relationship we build, from the first order to the hundredth.'
                        ],
                        [
                            'title' => 'Energy & Growth',
                            'color' => 'Yellow',
                            'description' => 'We foster a culture of positivity, ambition and warmth that keeps people moving forward together.'
                        ],
                        [
                            'title' => 'Integrity & Transparency',
                            'color' => 'White',
                            'description' => 'Our foundation is ethical values and honest, simple business practice. Nothing hidden in the fine print.'
                        ]
                    ]
                ]
            ],

            // Chapter Six - Opportunity
            [
                'section' => 'chapter_six',
                'section_title' => 'CHAPTER SIX · THE OPPORTUNITY',
                'section_subtitle' => 'A growth ladder for leaders',
                'description' => 'A clear, milestone-driven journey built on leadership development, mentorship and shared success. Every rung is earned, and every rung is published.',
                'order' => 10,
                'content_data' => [
                    'levels' => [
                        [
                            'level' => '01',
                            'title' => 'Associate',
                            'description' => 'Begin your journey with curated products, structured training and a community that answers when you ask.'
                        ],
                        [
                            'level' => '02',
                            'title' => 'Builder',
                            'description' => 'Grow your network and unlock deeper mentorship as your circle expands beyond the people you already knew.'
                        ],
                        [
                            'level' => '03',
                            'title' => 'Leader',
                            'description' => 'Guide your own team with recognition, tools and rewards designed around leadership rather than volume alone.'
                        ]
                    ]
                ]
            ],
        ];

        foreach ($sections as $section) {
            LandingPage::create($section);
        }
    }
}
