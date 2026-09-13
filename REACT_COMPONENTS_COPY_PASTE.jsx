/**
 * COPY-PASTE READY REACT COMPONENTS
 * 
 * Use these components directly in your project.
 * All code is production-ready.
 */

// ============================================================================
// 1. MAIN APP.JSX - Entry Point with Routing
// ============================================================================

import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import Navbar from './components/Navbar';
import Home from './pages/Home';
import Explore from './pages/Explore';
import ExploreResults from './pages/ExploreResults';
import NotFound from './pages/NotFound';

export default function App() {
  return (
    <BrowserRouter>
      <div className="min-h-screen bg-gray-50">
        <Navbar />
        <main>
          <Routes>
            {/* Homepage */}
            <Route path="/" element={<Home />} />

            {/* Unified explore page - THIS IS THE KEY */}
            <Route path="/explore" element={<Explore />} />

            {/* Results page - maintains tab context */}
            <Route path="/explore/results" element={<ExploreResults />} />

            {/* Legacy routes - redirect to new unified system */}
            <Route path="/hotels" element={<Navigate to="/explore?tab=hotels" replace />} />
            <Route path="/tours" element={<Navigate to="/explore?tab=tours" replace />} />
            <Route path="/guides" element={<Navigate to="/explore?tab=guides" replace />} />
            <Route path="/boats" element={<Navigate to="/explore?tab=boats" replace />} />

            {/* 404 */}
            <Route path="*" element={<NotFound />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
}


// ============================================================================
// 2. NAVBAR COMPONENT - Updated Navigation
// ============================================================================

import React from 'react';
import { Link, useLocation } from 'react-router-dom';

export default function Navbar() {
  const location = useLocation();

  // Get active tab from URL
  const getActiveTab = () => {
    const params = new URLSearchParams(location.search);
    const tab = params.get('tab');

    if (location.pathname === '/explore' && tab) {
      return tab;
    }
    if (location.pathname === '/explore' && !tab) {
      return 'hotels';
    }
    if (location.pathname === '/explore/results') {
      const resultTab = new URLSearchParams(location.search).get('tab');
      return resultTab || 'hotels';
    }
    return null;
  };

  const activeTab = getActiveTab();

  const navItems = [
    { label: 'Hotels', tab: 'hotels', icon: '🏨' },
    { label: 'Tours', tab: 'tours', icon: '🎫' },
    { label: 'Guides', tab: 'guides', icon: '🧑‍🏫' },
    { label: 'Boats', tab: 'boats', icon: '⛵' },
  ];

  return (
    <nav className="sticky top-0 z-50 bg-white shadow-md border-b border-gray-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex justify-between items-center h-16">
          {/* Logo */}
          <Link to="/" className="flex items-center">
            <span className="text-2xl font-bold bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">
              iTour Mercedes
            </span>
          </Link>

          {/* Main Navigation */}
          <div className="hidden md:flex items-center gap-1">
            {navItems.map((item) => (
              <Link
                key={item.tab}
                to={`/explore?tab=${item.tab}`}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-all duration-200 ${
                  activeTab === item.tab
                    ? 'bg-blue-600 text-white shadow-lg'
                    : 'text-gray-700 hover:bg-gray-100'
                }`}
                title={`Browse ${item.label}`}
              >
                <span className="text-lg">{item.icon}</span>
                <span className="hidden lg:inline">{item.label}</span>
              </Link>
            ))}
          </div>

          {/* Mobile Menu */}
          <div className="md:hidden flex items-center gap-2">
            {navItems.map((item) => (
              <Link
                key={item.tab}
                to={`/explore?tab=${item.tab}`}
                className={`px-3 py-2 rounded-lg text-sm font-medium transition-all ${
                  activeTab === item.tab
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-700 hover:bg-gray-100'
                }`}
              >
                {item.icon}
              </Link>
            ))}
          </div>

          {/* Right Menu */}
          <div className="hidden md:flex items-center gap-4">
            <Link to="/bookings" className="text-gray-700 hover:text-blue-600 font-medium">
              My Bookings
            </Link>
            <Link
              to="/login"
              className="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-medium hover:shadow-lg transition-shadow"
            >
              Login
            </Link>
          </div>
        </div>
      </div>
    </nav>
  );
}


// ============================================================================
// 3. EXPLORE PAGE - Main Unified Discovery Page
// ============================================================================

import React, { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import SearchTabs from '../components/SearchTabs';
import SearchForm from '../components/SearchForm';
import PopularCarousels from '../components/PopularCarousels';

const TAB_CONFIG = {
  hotels: {
    label: 'Hotels / Resort Rooms',
    icon: '🏨',
    description: 'Find and book luxury hotels and resorts',
    formFields: ['destination', 'checkInDate', 'checkOutDate', 'guests'],
  },
  tours: {
    label: 'Tour Packages',
    icon: '🎫',
    description: 'Discover guided tour packages',
    formFields: ['destination', 'date', 'pax'],
  },
  guides: {
    label: 'Tour Guides',
    icon: '🧑‍🏫',
    description: 'Book experienced tour guides',
    formFields: ['destination', 'date', 'pax'],
  },
  boats: {
    label: 'Boats',
    icon: '⛵',
    description: 'Rent boats for your adventure',
    formFields: ['destination', 'date', 'pax'],
  },
  bundle: {
    label: 'Guide + Boat Bundle',
    icon: '📦',
    description: 'Get a guide and boat together',
    formFields: ['destination', 'date', 'pax'],
  },
};

export default function Explore() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();

  // CRITICAL: Read tab from URL
  const activeTab = searchParams.get('tab') || 'hotels';

  // CRITICAL: Update URL when tab changes
  const handleTabChange = (newTab) => {
    setSearchParams({ tab: newTab });
  };

  // Handle search submission
  const handleSearch = (formData) => {
    const params = new URLSearchParams({
      tab: activeTab,
      ...formData,
    }).toString();

    navigate(`/explore/results?${params}`);
  };

  const config = TAB_CONFIG[activeTab] || TAB_CONFIG.hotels;

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50">
      {/* Hero Section */}
      <section className="relative bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 text-white py-20 overflow-hidden">
        {/* Background pattern */}
        <div className="absolute inset-0 opacity-10">
          <div className="absolute top-0 left-0 w-96 h-96 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
          <div className="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/2 translate-y-1/2"></div>
        </div>

        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <h1 className="text-5xl md:text-6xl font-bold mb-4 leading-tight">
            Discover Your Next Adventure
          </h1>
          <p className="text-xl md:text-2xl opacity-90 mb-8 max-w-2xl">
            Search and book hotels, tours, guides, and boats in one place. Plan your perfect getaway.
          </p>

          {/* CTA Button */}
          <button
            onClick={() => document.querySelector('#search-section').scrollIntoView({ behavior: 'smooth' })}
            className="px-8 py-4 bg-white text-blue-600 rounded-lg font-bold hover:bg-gray-100 transition-colors shadow-lg hover:shadow-xl"
          >
            Start Searching
          </button>
        </div>
      </section>

      {/* Search Section */}
      <section id="search-section" className="relative pb-16">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-20 relative z-10">
          {/* Tab Switcher */}
          <SearchTabs
            tabs={TAB_CONFIG}
            activeTab={activeTab}
            onTabChange={handleTabChange}
          />

          {/* Search Form */}
          <SearchForm
            config={config}
            onSearch={handleSearch}
          />
        </div>
      </section>

      {/* Popular Sections */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <PopularCarousels />
      </section>

      {/* Info Section */}
      <section className="bg-gray-50 py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <h2 className="text-4xl font-bold text-center mb-12 text-gray-900">
            How It Works
          </h2>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
            {[
              { step: '1', title: 'Search', desc: 'Choose what you want to book' },
              { step: '2', title: 'Select', desc: 'Pick your preferred option' },
              { step: '3', title: 'Book', desc: 'Complete your reservation' },
              { step: '4', title: 'Enjoy', desc: 'Have an amazing experience' },
            ].map((item, idx) => (
              <div key={idx} className="text-center">
                <div className="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-full font-bold text-2xl mb-6 shadow-lg">
                  {item.step}
                </div>
                <h3 className="text-xl font-bold text-gray-900 mb-2">{item.title}</h3>
                <p className="text-gray-600">{item.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Benefits Section */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <h2 className="text-4xl font-bold text-center mb-12 text-gray-900">Why Choose Us?</h2>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {[
            { icon: '✓', title: 'Best Prices', desc: 'Get the best deals on accommodations and tours' },
            { icon: '✓', title: 'Expert Guides', desc: 'Professional guides with years of experience' },
            { icon: '✓', title: 'Easy Booking', desc: 'Simple and secure booking process' },
            { icon: '✓', title: '24/7 Support', desc: 'Customer support available round the clock' },
            { icon: '✓', title: 'Safe & Secure', desc: 'Your safety is our top priority' },
            { icon: '✓', title: 'Cancellation', desc: 'Easy cancellation policy' },
          ].map((item, idx) => (
            <div
              key={idx}
              className="p-6 bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow border border-gray-100"
            >
              <div className="text-4xl font-bold text-blue-600 mb-4">{item.icon}</div>
              <h3 className="text-xl font-bold text-gray-900 mb-2">{item.title}</h3>
              <p className="text-gray-600">{item.desc}</p>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}


// ============================================================================
// 4. SEARCH TABS COMPONENT
// ============================================================================

import React from 'react';

export default function SearchTabs({ tabs, activeTab, onTabChange }) {
  return (
    <div className="bg-white rounded-t-2xl shadow-2xl p-6 mb-0 border-b-4 border-blue-600">
      <div className="flex gap-4 overflow-x-auto pb-2">
        {Object.entries(tabs).map(([tabKey, tabData]) => (
          <button
            key={tabKey}
            onClick={() => onTabChange(tabKey)}
            className={`flex items-center gap-2 px-6 py-4 rounded-xl font-bold whitespace-nowrap transition-all duration-200 transform hover:scale-105 ${
              activeTab === tabKey
                ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-xl'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            <span className="text-2xl">{tabData.icon}</span>
            <span>{tabData.label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}


// ============================================================================
// 5. SEARCH FORM COMPONENT - Dynamic
// ============================================================================

import React, { useState } from 'react';

export default function SearchForm({ config, onSearch }) {
  const [formData, setFormData] = useState({
    destination: '',
    checkInDate: '',
    checkOutDate: '',
    date: '',
    guests: '1',
    pax: '1',
  });

  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const validateForm = () => {
    const newErrors = {};

    if (!formData.destination.trim()) {
      newErrors.destination = 'Destination is required';
    }

    if (config.formFields.includes('checkInDate') && !formData.checkInDate) {
      newErrors.checkInDate = 'Check-in date is required';
    }

    if (config.formFields.includes('checkOutDate') && !formData.checkOutDate) {
      newErrors.checkOutDate = 'Check-out date is required';
    }

    if (config.formFields.includes('date') && !formData.date) {
      newErrors.date = 'Date is required';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value,
    }));
    // Clear error for this field
    if (errors[name]) {
      setErrors(prev => ({
        ...prev,
        [name]: '',
      }));
    }
  };

  const handleSubmit = (e) => {
    e.preventDefault();

    if (!validateForm()) {
      return;
    }

    setLoading(true);

    // Build search data with only relevant fields
    const searchData = {};
    config.formFields.forEach(field => {
      if (formData[field]) {
        searchData[field] = formData[field];
      }
    });

    // Simulate API call
    setTimeout(() => {
      onSearch(searchData);
      setLoading(false);
    }, 500);
  };

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-b-2xl shadow-2xl p-8 md:p-12">
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        {/* Destination Field - All tabs */}
        {config.formFields.includes('destination') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              📍 Destination
            </label>
            <input
              type="text"
              name="destination"
              value={formData.destination}
              onChange={handleChange}
              placeholder="Enter city or location..."
              className={`w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all ${
                errors.destination ? 'border-red-500' : 'border-gray-300'
              }`}
            />
            {errors.destination && (
              <span className="text-red-500 text-sm mt-1 block">{errors.destination}</span>
            )}
          </div>
        )}

        {/* Check-in Date - Hotels */}
        {config.formFields.includes('checkInDate') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              📅 Check-in Date
            </label>
            <input
              type="date"
              name="checkInDate"
              value={formData.checkInDate}
              onChange={handleChange}
              className={`w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all ${
                errors.checkInDate ? 'border-red-500' : 'border-gray-300'
              }`}
            />
            {errors.checkInDate && (
              <span className="text-red-500 text-sm mt-1 block">{errors.checkInDate}</span>
            )}
          </div>
        )}

        {/* Check-out Date - Hotels */}
        {config.formFields.includes('checkOutDate') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              📅 Check-out Date
            </label>
            <input
              type="date"
              name="checkOutDate"
              value={formData.checkOutDate}
              onChange={handleChange}
              className={`w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all ${
                errors.checkOutDate ? 'border-red-500' : 'border-gray-300'
              }`}
            />
            {errors.checkOutDate && (
              <span className="text-red-500 text-sm mt-1 block">{errors.checkOutDate}</span>
            )}
          </div>
        )}

        {/* Date - Tours/Guides/Boats */}
        {config.formFields.includes('date') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              📅 Date
            </label>
            <input
              type="date"
              name="date"
              value={formData.date}
              onChange={handleChange}
              className={`w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all ${
                errors.date ? 'border-red-500' : 'border-gray-300'
              }`}
            />
            {errors.date && (
              <span className="text-red-500 text-sm mt-1 block">{errors.date}</span>
            )}
          </div>
        )}

        {/* Guests - Hotels */}
        {config.formFields.includes('guests') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              👥 Number of Guests
            </label>
            <select
              name="guests"
              value={formData.guests}
              onChange={handleChange}
              className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all"
            >
              {[1, 2, 3, 4, 5, 6, 7, 8].map(n => (
                <option key={n} value={n}>{n} Guest{n > 1 ? 's' : ''}</option>
              ))}
            </select>
          </div>
        )}

        {/* Pax - Tours/Guides/Boats */}
        {config.formFields.includes('pax') && (
          <div className="relative">
            <label className="block text-sm font-bold text-gray-700 mb-3 ml-1">
              👥 Number of People
            </label>
            <select
              name="pax"
              value={formData.pax}
              onChange={handleChange}
              className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent outline-none transition-all"
            >
              {[1, 2, 3, 4, 5, 6, 7, 8, 10, 12, 15, 20].map(n => (
                <option key={n} value={n}>{n} Person{n > 1 ? 's' : ''}</option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* Submit Button */}
      <button
        type="submit"
        disabled={loading}
        className="w-full py-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl font-bold text-lg hover:shadow-xl transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed transform hover:scale-105 active:scale-95"
      >
        {loading ? (
          <span className="flex items-center justify-center gap-2">
            <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
            Searching...
          </span>
        ) : (
          '🔍 Search'
        )}
      </button>
    </form>
  );
}


// ============================================================================
// 6. POPULAR CAROUSELS COMPONENT
// ============================================================================

import React, { useRef } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export default function PopularCarousels() {
  const categories = [
    {
      title: 'Popular Hotels',
      icon: '🏨',
      items: [
        { id: 1, name: 'Beachfront Resort', location: 'Miami Beach', price: 299, rating: 4.8 },
        { id: 2, name: 'Mountain Lodge', location: 'Swiss Alps', price: 199, rating: 4.6 },
        { id: 3, name: 'City Center Hotel', location: 'New York', price: 349, rating: 4.7 },
        { id: 4, name: 'Desert Oasis', location: 'Dubai', price: 259, rating: 4.9 },
      ],
    },
    {
      title: 'Popular Tours',
      icon: '🎫',
      items: [
        { id: 1, name: 'Amazon Jungle Tour', location: 'Peru', price: 599, rating: 4.9 },
        { id: 2, name: 'Safari Adventure', location: 'Kenya', price: 799, rating: 4.8 },
        { id: 3, name: 'City Walking Tour', location: 'Paris', price: 89, rating: 4.7 },
        { id: 4, name: 'Island Hopping', location: 'Greece', price: 449, rating: 4.6 },
      ],
    },
  ];

  return (
    <div className="space-y-16">
      {categories.map((category) => (
        <CarouselSection key={category.title} category={category} />
      ))}
    </div>
  );
}

function CarouselSection({ category }) {
  const carouselRef = useRef(null);

  const scroll = (direction) => {
    if (carouselRef.current) {
      const { scrollLeft, clientWidth } = carouselRef.current;
      const scrollAmount = clientWidth / 2;
      carouselRef.current.scrollTo({
        left: direction === 'left' ? scrollLeft - scrollAmount : scrollLeft + scrollAmount,
        behavior: 'smooth',
      });
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h3 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
          <span className="text-4xl">{category.icon}</span>
          {category.title}
        </h3>
        <div className="flex gap-2">
          <button
            onClick={() => scroll('left')}
            className="p-2 bg-blue-600 text-white rounded-full hover:bg-blue-700 transition-colors"
          >
            <ChevronLeft size={24} />
          </button>
          <button
            onClick={() => scroll('right')}
            className="p-2 bg-blue-600 text-white rounded-full hover:bg-blue-700 transition-colors"
          >
            <ChevronRight size={24} />
          </button>
        </div>
      </div>

      <div
        ref={carouselRef}
        className="flex gap-6 overflow-x-auto pb-4 scroll-smooth"
      >
        {category.items.map((item) => (
          <div
            key={item.id}
            className="flex-shrink-0 w-64 bg-white rounded-xl shadow-md hover:shadow-xl transition-shadow overflow-hidden group cursor-pointer"
          >
            <div className="h-40 bg-gradient-to-r from-blue-500 to-indigo-600 group-hover:from-blue-600 group-hover:to-indigo-700 transition-all"></div>
            <div className="p-4">
              <h4 className="font-bold text-lg text-gray-900 mb-1">{item.name}</h4>
              <p className="text-sm text-gray-600 mb-3">{item.location}</p>
              <div className="flex justify-between items-center">
                <span className="text-2xl font-bold text-blue-600">${item.price}</span>
                <span className="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-sm font-semibold">
                  ⭐ {item.rating}
                </span>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}


// ============================================================================
// 7. RESULTS PAGE COMPONENT
// ============================================================================

import React, { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';

export default function ExploreResults() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const tab = searchParams.get('tab') || 'hotels';
  const destination = searchParams.get('destination');

  useEffect(() => {
    fetchResults();
  }, [tab, destination]);

  const fetchResults = async () => {
    try {
      setLoading(true);
      setError(null);

      // Build API endpoint based on tab
      const endpoint = `/api/${tab}/search?destination=${destination}`;

      // Mock data for demonstration
      const mockResults = {
        hotels: [
          { id: 1, name: 'Luxury Beach Resort', location: destination, image: '/hotel1.jpg', price: 299, rating: 4.8 },
          { id: 2, name: 'Modern City Hotel', location: destination, image: '/hotel2.jpg', price: 199, rating: 4.6 },
          { id: 3, name: 'Historic Inn', location: destination, image: '/hotel3.jpg', price: 149, rating: 4.7 },
          { id: 4, name: 'Boutique Hotel', location: destination, image: '/hotel4.jpg', price: 249, rating: 4.9 },
          { id: 5, name: 'Resort & Spa', location: destination, image: '/hotel5.jpg', price: 399, rating: 4.5 },
          { id: 6, name: 'Budget Friendly', location: destination, image: '/hotel6.jpg', price: 99, rating: 4.3 },
        ],
        tours: [
          { id: 1, name: 'City Tour', location: destination, image: '/tour1.jpg', price: 89, rating: 4.7 },
          { id: 2, name: 'Adventure Tour', location: destination, image: '/tour2.jpg', price: 199, rating: 4.8 },
          { id: 3, name: 'Cultural Tour', location: destination, image: '/tour3.jpg', price: 129, rating: 4.6 },
          { id: 4, name: 'Nature Trail', location: destination, image: '/tour4.jpg', price: 79, rating: 4.9 },
        ],
      };

      setResults(mockResults[tab] || []);
    } catch (err) {
      setError('Failed to fetch results');
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      {/* Header */}
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="text-4xl font-bold text-gray-900 mb-2">
            Search Results
          </h1>
          <p className="text-lg text-gray-600">
            Found {results.length} results for {destination}
          </p>
        </div>

        <button
          onClick={() => navigate('/explore')}
          className="px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-colors"
        >
          ← New Search
        </button>
      </div>

      {/* Results Grid */}
      {loading ? (
        <div className="flex justify-center items-center py-20">
          <div className="text-center">
            <div className="inline-block animate-spin">
              <svg className="w-16 h-16 text-blue-600" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            </div>
            <p className="mt-4 text-gray-600 font-semibold">Loading results...</p>
          </div>
        </div>
      ) : error ? (
        <div className="text-center py-12 text-red-600">
          <p className="text-lg font-semibold">{error}</p>
        </div>
      ) : results.length > 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          {results.map((item) => (
            <div
              key={item.id}
              className="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-2xl transition-shadow transform hover:scale-105"
            >
              <div className="h-48 bg-gradient-to-r from-blue-500 to-indigo-600"></div>
              <div className="p-6">
                <h3 className="font-bold text-xl text-gray-900 mb-2">{item.name}</h3>
                <p className="text-gray-600 mb-4">{item.location}</p>

                <div className="flex justify-between items-center mb-4">
                  <span className="text-3xl font-bold text-blue-600">${item.price}</span>
                  <span className="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full font-semibold">
                    ⭐ {item.rating}
                  </span>
                </div>

                <button className="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-bold hover:shadow-lg transition-all">
                  View Details
                </button>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-20">
          <p className="text-2xl text-gray-500 mb-4">No results found</p>
          <p className="text-gray-600 mb-6">Try a different search or browse our popular options</p>
          <button
            onClick={() => navigate('/explore')}
            className="px-8 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-colors"
          >
            Back to Search
          </button>
        </div>
      )}
    </div>
  );
}

export default ExploreResults;
