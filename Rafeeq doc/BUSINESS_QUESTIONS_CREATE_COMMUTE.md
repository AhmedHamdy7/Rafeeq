# Create Commute: questions for Product

The backend works today with the answer shown for each one. Tell us if any should change.

| # | Question | What's built today |
|---|---|---|
| 1 | The form has no dates. How long should a commute run by default? No rule sets this. | 12 months from tomorrow, the longest allowed |
| 2 | When a commute's end date passes, should the driver be told first? | It's archived the next night, with no notice |
| 3 | The form never asks which way a commute runs. Do passengers need to see "to work" or "to home"? | Saved as `to_work`, flipped on Add Return. Flutter is told not to show it |
| 4 | The driver can tick AC on a car the reviewer marked as having none. Which one do passengers see? A passenger who filters for AC can still match this commute. | Both are sent. Flutter is told to show the car's value and lock the switch |
| 5 | Should a rider who can't fit the driver's earliest departure be refused, or offered to the driver anyway? COM-15 says times move "inside the agreed windows". | Refused |
| 6 | COM-39 says a woman driver can mark Women-only. Should the server refuse it from anyone else? | The app is told to hide the switch. The server doesn't refuse it |
| 7 | COM-39 has five comfort chips and the Create design has three. Which is right? | Five, as the rule says |
| 8 | A draft can hold a rejected or unreviewed car, because COM-07 only blocks publishing. Is that right? | Allowed |
