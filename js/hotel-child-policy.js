(function (global) {
  'use strict';
  const rate = 250;
  function ages(values, count) {
    if (!Array.isArray(values) || values.length !== count) throw new Error('Select an age for every child.');
    return values.map(value => {
      if (!/^(?:0|[1-9]\d?)$/.test(String(value).trim()) || Number(value) > 17) throw new Error('Child ages must be whole numbers from 0 to 17. Age 18+ is an adult.');
      return Number(value);
    });
  }
  function quote(policy, adults, children, values, nights, roomRate) {
    if (!policy || !['adult_capacity', 'child_allowance', 'max_occupancy'].every(key => Number.isInteger(policy[key]) && policy[key] >= 0)) throw new Error('Select a room with a valid occupancy policy.');
    if (!Number.isInteger(adults) || adults < 1 || !Number.isInteger(children) || children < 0 || adults + children > 100) throw new Error('Enter valid adult and child counts.');
    const childAges = ages(values, children);
    if (adults > policy.adult_capacity) throw new Error('Maximum adult capacity exceeded');
    if (children > policy.child_allowance) throw new Error('Maximum child allowance exceeded');
    if (adults + children > policy.max_occupancy) throw new Error('Maximum approved occupancy exceeded');
    if (!Number.isInteger(nights) || nights < 1) throw new Error('Select valid stay dates.');
    const paying = childAges.filter(age => age >= 6).length;
    const roomSubtotal = Math.round(roomRate * 100) * nights / 100;
    return { childAges, freeChildren: children - paying, payingChildren: paying,
      childFeePerNight: paying * rate, childFeeTotal: paying * rate * nights,
      roomSubtotal, total: roomSubtotal + paying * rate * nights };
  }
  function ageLabel(age) { return Number(age) <= 5 ? 'Free' : 'PHP 250/night'; }
  global.HotelChildPolicy = Object.freeze({ rate, ages, quote, ageLabel });
})(window);
